<?php

namespace App\Controller;

use App\Entity\Exercise;
use App\Entity\Routine;
use App\Entity\WorkoutSession;
use App\Entity\WorkoutSetLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/workouts', name: 'api_workouts_')]
class WorkoutLogController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('', name: 'list_sessions', methods: ['GET'])]
    public function listSessions(Request $request): JsonResponse
    {
        if (!$user = $this->getUser()) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $status = $request->query->get('status');
        if ($status !== null && !in_array($status, ['active', 'completed'], true)) {
            return $this->json(['error' => 'Invalid status'], 400);
        }
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 25)));
        $qb = $this->em->createQueryBuilder()->from(WorkoutSession::class, 's')
            ->where('s.user = :user')->setParameter('user', $user);
        if ($status !== null) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        // Paginate sessions, not joined set rows: a long workout occupies one result.
        $sessions = $qb->select('s')->orderBy('s.date', 'DESC')->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
        $response = $this->json(array_map(fn (WorkoutSession $s) => $this->serializeSession($s, $request->query->getBoolean('include_sets')), $sessions));
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Per-Page', (string) $limit);
        return $response;
    }

    #[Route('/exercise-summaries', name: 'exercise_summaries', methods: ['POST'])]
    public function exerciseSummaries(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        $ids = $data['exercise_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 100) {
            return $this->json(['error' => 'exercise_ids must be an array of at most 100 IDs'], 400);
        }
        $summaries = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) continue;
            $sets = $this->recordSets($id);
            $lastSession = ($sets[0] ?? null)?->getSession();
            $lastSets = [];
            foreach ($sets as $set) {
                if ($set->getSession() === $lastSession) {
                    $lastSets[] = $this->serializeSet($set);
                }
            }
            usort($lastSets, fn (array $a, array $b) => $a['set_number'] <=> $b['set_number']);
            $summaries[$id] = [
                'last_session' => $lastSession ? [
                    'session_id' => $lastSession->getId()->toRfc4122(),
                    'date' => $lastSession->getDate()->format('Y-m-d'),
                    'sets' => $lastSets,
                ] : null,
                'records' => $sets ? $this->records($sets) : null,
            ];
        }
        return $this->json(['summaries' => $summaries ?: (object) []]);
    }

    #[Route('/{sessionId}', name: 'get_session', methods: ['GET'])]
    public function getSession(string $sessionId): JsonResponse
    {
        $session = $this->ownedSession($sessionId);
        return $session instanceof JsonResponse ? $session : $this->json($this->serializeSession($session, true));
    }

    #[Route('/{sessionId}', name: 'update_session', methods: ['PATCH'])]
    public function updateSession(string $sessionId, Request $request): JsonResponse
    {
        $session = $this->ownedSession($sessionId);
        if ($session instanceof JsonResponse) return $session;
        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        if (array_key_exists('status', $data) && $data['status'] !== 'completed') {
            return $this->json(['error' => 'A session may only transition to completed'], 400);
        }
        $name = $session->getName();
        if (array_key_exists('name', $data)) {
            if (!is_string($data['name']) || ($name = trim($data['name'])) === '' || mb_strlen($name) > 255) {
                return $this->json(['error' => 'name must contain between 1 and 255 characters'], 400);
            }
        }
        $date = $session->getDate();
        if (array_key_exists('date', $data)) {
            $date = $this->parseSessionDate($data['date']);
            if ($date === null) return $this->json(['error' => 'date must be a valid ISO date or datetime'], 400);
        }
        $duration = $session->getDurationSeconds();
        if (array_key_exists('duration_minutes', $data)) {
            $minutes = filter_var($data['duration_minutes'], FILTER_VALIDATE_INT);
            if ($minutes === false || $minutes < 0 || $minutes > 10080) {
                return $this->json(['error' => 'duration_minutes must be between 0 and 10080'], 400);
            }
            $duration = $minutes * 60;
        }
        if (array_key_exists('duration_seconds', $data)) {
            if (!is_int($data['duration_seconds']) || $data['duration_seconds'] < 0 || $data['duration_seconds'] > 604800) {
                return $this->json(['error' => 'duration_seconds must be an integer between 0 and 604800'], 400);
            }
            $duration = $data['duration_seconds'];
        }
        if ($session->getStatus() === 'completed') {
            // Retrying a successful finish is safe; finished sessions cannot reopen.
            return $this->json($this->serializeSession($session, true));
        }
        if (($data['status'] ?? null) === 'completed'
            && !$session->getSets()->exists(fn ($key, WorkoutSetLog $set) => $set->isCompleted())) {
            return $this->json(['error' => 'Completa al menos una serie o descarta la sesión'], 400);
        }
        $session->setName($name)->setDate($date)->setDurationSeconds($duration);
        if (($data['status'] ?? null) === 'completed') $session->complete();
        $this->em->flush();
        return $this->json($this->serializeSession($session, true));
    }

    #[Route('/{sessionId}', name: 'delete_session', methods: ['DELETE'])]
    public function deleteSession(string $sessionId): JsonResponse
    {
        $session = $this->ownedSession($sessionId);
        if ($session instanceof JsonResponse) return $session;
        $this->em->remove($session);
        $this->em->flush();
        return $this->json(['message' => 'Session deleted successfully']);
    }

    #[Route('', name: 'start_session', methods: ['POST'])]
    public function startSession(Request $request): JsonResponse
    {
        if (!$user = $this->getUser()) return $this->json(['error' => 'Unauthorized'], 401);
        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        $clientId = $data['client_session_id'] ?? null;
        if ($clientId !== null && (!is_string($clientId) || !Uuid::isValid($clientId))) {
            return $this->json(['error' => 'Invalid client_session_id'], 400);
        }
        if ($clientId !== null) {
            $existing = $this->em->getRepository(WorkoutSession::class)->findOneBy(['user' => $user, 'clientSessionId' => $clientId]);
            if ($existing) {
                return $this->json($this->serializeSession($existing, true));
            }
        }
        $session = (new WorkoutSession())->setUser($user)->setClientSessionId($clientId);
        if (isset($data['routine_id'])) {
            if (!is_string($data['routine_id']) || !Uuid::isValid($data['routine_id'])) {
                return $this->json(['error' => 'Invalid routine_id format'], 400);
            }
            $routine = $this->em->getRepository(Routine::class)->find($data['routine_id']);
            if (!$routine) return $this->json(['error' => 'Routine not found'], 404);
            if ($routine->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);
            $session->setRoutine($routine);
        }
        $session->setName($session->getRoutine()?->getName() ?? 'Entrenamiento');
        $this->em->persist($session);
        $this->em->flush();
        return $this->json($this->serializeSession($session, true), 201);
    }

    #[Route('/{sessionId}/sets', name: 'log_set', methods: ['POST'])]
    public function logSet(string $sessionId, Request $request): JsonResponse
    {
        $session = $this->ownedSession($sessionId, true);
        if ($session instanceof JsonResponse) return $session;
        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        $clientId = $data['client_set_id'] ?? null;
        if ($clientId !== null && (!is_string($clientId) || $clientId === '' || strlen($clientId) > 100)) {
            return $this->json(['error' => 'Invalid client_set_id'], 400);
        }
        if ($clientId !== null) {
            $existing = $this->em->getRepository(WorkoutSetLog::class)->findOneBy(['session' => $session, 'clientSetId' => $clientId]);
            if ($existing) {
                if ($existing->getExercise()->getId()->toRfc4122() !== ($data['exercise_id'] ?? null)) {
                    return $this->json(['error' => 'client_set_id belongs to a different exercise'], 409);
                }
                return $this->json(['setId' => $existing->getId()->toRfc4122()] + $this->serializeSet($existing));
            }
        }
        $exerciseId = $data['exercise_id'] ?? null;
        if (!is_string($exerciseId) || !Uuid::isValid($exerciseId)) return $this->json(['error' => 'Missing or invalid exercise_id'], 400);
        $exercise = $this->em->getRepository(Exercise::class)->find($exerciseId);
        if (!$exercise) return $this->json(['error' => 'Exercise not found'], 404);
        $set = (new WorkoutSetLog())->setExercise($exercise)->setClientSetId($clientId)->setCompleted(true);
        $error = $this->applySetData($set, $data, true);
        if ($error) return $error;
        $prior = $this->records($this->recordSets($exerciseId));
        $session->addSet($set);
        $this->em->persist($set);
        $this->em->flush();
        $prTypes = $this->newRecordTypes($prior, $this->records([$set]));
        $e1rm = WorkoutSetLog::estimateOneRepMax($set->getWeight(), $set->getReps());
        return $this->json([
            'setId' => $set->getId()->toRfc4122(), 'is_pr' => count($prTypes) > 0, 'pr_types' => $prTypes,
            'previous_max_weight' => $prior['max_weight'], 'new_weight' => $set->getWeight(),
            'previous_max_e1rm' => $prior['max_e1rm'], 'new_e1rm' => $e1rm,
        ] + $this->serializeSet($set), 201);
    }

    #[Route('/{sessionId}/sets/{setId}', name: 'delete_set', methods: ['DELETE'])]
    public function deleteSet(string $sessionId, string $setId): JsonResponse
    {
        $session = $this->ownedSession($sessionId, true);
        if ($session instanceof JsonResponse) return $session;
        $set = Uuid::isValid($setId) ? $this->em->getRepository(WorkoutSetLog::class)->find($setId) : null;
        if (!$set || $set->getSession() !== $session) return $this->json(['error' => 'Set not found in this session'], 404);
        $session->removeSet($set);
        $this->em->remove($set);
        $this->em->flush();
        return $this->json(['message' => 'Set deleted successfully']);
    }

    #[Route('/{sessionId}/sets/{setId}', name: 'update_set', methods: ['PATCH'])]
    public function updateSet(string $sessionId, string $setId, Request $request): JsonResponse
    {
        $session = $this->ownedSession($sessionId, true);
        if ($session instanceof JsonResponse) return $session;
        $set = Uuid::isValid($setId) ? $this->em->getRepository(WorkoutSetLog::class)->find($setId) : null;
        if (!$set || $set->getSession() !== $session) return $this->json(['error' => 'Set not found in this session'], 404);
        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        $error = $this->applySetData($set, $data);
        if ($error) return $error;
        $this->em->flush();
        return $this->json($this->serializeSet($set));
    }

    private function ownedSession(string $id, bool $active = false): WorkoutSession|JsonResponse
    {
        if (!$user = $this->getUser()) return $this->json(['error' => 'Unauthorized'], 401);
        $session = Uuid::isValid($id) ? $this->em->getRepository(WorkoutSession::class)->find($id) : null;
        if (!$session) return $this->json(['error' => 'Session not found'], 404);
        if ($session->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);
        if ($active && $session->getStatus() !== 'active') return $this->json(['error' => 'Session already completed'], 409);
        return $session;
    }

    private function applySetData(WorkoutSetLog $set, array $data, bool $creating = false): ?JsonResponse
    {
        $cardio = in_array($set->getExercise()->getTrackingType(), [Exercise::TRACKING_DISTANCE_DURATION, Exercise::TRACKING_DURATION], true)
            || strcasecmp((string) $set->getExercise()->getMuscleGroup(), 'Cardio') === 0;
        $weight = $data['weight'] ?? ($creating && !$cardio ? null : $set->getWeight());
        $reps = $data['reps'] ?? ($creating && !$cardio ? null : $set->getReps());
        $weight = filter_var($weight, FILTER_VALIDATE_FLOAT);
        $reps = filter_var($reps, FILTER_VALIDATE_INT);
        if ($weight === false || !is_finite((float) $weight) || $weight < 0 || $weight > 1000 || $reps === false || $reps < ($cardio ? 0 : 1) || $reps > 1000) {
            return $this->json(['error' => 'Invalid weight or reps'], 400);
        }
        $type = $data['set_type'] ?? $set->getSetType();
        if (!in_array($type, ['normal', 'warmup', 'drop_set', 'failure'], true)) return $this->json(['error' => 'Invalid set_type'], 400);
        $completed = $data['completed'] ?? $set->isCompleted();
        if (!is_bool($completed)) return $this->json(['error' => 'completed must be a boolean'], 400);
        $number = filter_var($data['set_number'] ?? $set->getSetNumber(), FILTER_VALIDATE_INT);
        if ($number === false || $number < 1 || $number > 1000) return $this->json(['error' => 'Invalid set_number'], 400);
        $order = array_key_exists('exercise_order', $data) ? $data['exercise_order'] : $set->getExerciseOrder();
        if (!is_int($order) || $order < 0 || $order > 2147483647) return $this->json(['error' => 'exercise_order must be a nonnegative integer'], 400);
        $duration = array_key_exists('duration_seconds', $data) ? $data['duration_seconds'] : ($data['durationSeconds'] ?? $set->getDurationSeconds());
        $distance = array_key_exists('distance_km', $data) ? $data['distance_km'] : ($data['distanceKm'] ?? $set->getDistanceKm());
        if ($duration !== null && (filter_var($duration, FILTER_VALIDATE_INT) === false || $duration < 0 || $duration > 604800)) return $this->json(['error' => 'Invalid duration_seconds'], 400);
        if ($distance !== null && (filter_var($distance, FILTER_VALIDATE_FLOAT) === false || !is_finite((float) $distance) || $distance < 0 || $distance > 10000)) return $this->json(['error' => 'Invalid distance_km'], 400);
        $set->setWeight((float) $weight)->setReps((int) $reps)->setSetType($type)->setCompleted($completed)->setSetNumber($number)
            ->setExerciseOrder($order)->setDurationSeconds($duration !== null ? (int) $duration : null)->setDistanceKm($distance !== null ? (float) $distance : null);
        return null;
    }

    private function serializeSet(WorkoutSetLog $set): array
    {
        $exercise = $set->getExercise();
        return [
            'id' => $set->getId()->toRfc4122(), 'client_set_id' => $set->getClientSetId(), 'set_number' => $set->getSetNumber(),
            'exercise_order' => $set->getExerciseOrder(),
            'exercise_id' => $exercise->getId()->toRfc4122(), 'exercise_name' => $exercise->getName(),
            'muscle_group' => $exercise->getMuscleGroup(), 'tracking_type' => $exercise->getTrackingType(),
            'weight' => $set->getWeight(), 'reps' => $set->getReps(), 'completed' => $set->isCompleted(),
            'set_type' => $set->getSetType(), 'duration_seconds' => $set->getDurationSeconds(), 'distance_km' => $set->getDistanceKm(),
        ];
    }

    private function serializeSession(WorkoutSession $session, bool $includeSets): array
    {
        $volume = 0.0;
        $exercises = [];
        $completed = 0;
        $sets = [];
        foreach ($session->getSets() as $set) {
            if ($includeSets) $sets[] = $this->serializeSet($set);
            if (!$set->isCompleted()) continue;
            $completed++;
            $exercises[$set->getExercise()->getId()->toRfc4122()] = true;
            $volume += $set->getWeight() * $set->getReps();
        }
        usort($sets, fn (array $a, array $b) => ($a['exercise_order'] <=> $b['exercise_order']) ?: ($a['set_number'] <=> $b['set_number']) ?: strcmp($a['id'], $b['id']));
        $result = [
            'id' => $session->getId()->toRfc4122(), 'status' => $session->getStatus(),
            'name' => $session->getName(), 'duration_seconds' => $session->getDurationSeconds(),
            'routine_id' => $session->getRoutine()?->getId()?->toRfc4122(), 'routine_name' => $session->getRoutine()?->getName(),
            'date' => $session->getDate()->format('c'), 'duration_minutes' => $session->getDurationMinutes(),
            'total_volume' => round($volume, 1), 'exercise_count' => count($exercises), 'set_count' => $completed,
        ];
        if ($includeSets) {
            $result['sets'] = $sets;
            $result['personal_records'] = $this->sessionPersonalRecords($session);
        }
        return $result;
    }

    /** @return WorkoutSetLog[] */
    private function recordSets(string $exerciseId, ?WorkoutSession $before = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('sl', 's')->from(WorkoutSetLog::class, 'sl')->join('sl.session', 's')
            ->where('s.user = :user')->andWhere("s.status = 'completed'")->andWhere('sl.exercise = :exercise')
            ->andWhere('sl.completed = :completed')->setParameter('user', $this->getUser())->setParameter('exercise', $exerciseId)
            ->setParameter('completed', true)->orderBy('s.date', 'DESC')->addOrderBy('s.id', 'DESC')->addOrderBy('sl.setNumber', 'ASC')->addOrderBy('sl.id', 'ASC');
        if ($before !== null) {
            $qb->andWhere('(s.date < :date OR (s.date = :date AND s.id < :sessionId))')
                ->setParameter('date', $before->getDate())
                ->setParameter('sessionId', $before->getId()->toRfc4122());
        }
        return $qb->getQuery()->getResult();
    }

    private function records(array $sets): array
    {
        $records = ['max_weight' => 0.0, 'max_weight_set' => null, 'max_e1rm' => 0.0, 'max_e1rm_set' => null, 'max_reps' => 0, 'max_reps_set' => null];
        foreach ($sets as $set) {
            if (!$set->isCompleted() || $set->getSetType() === 'warmup' || $set->getWeight() <= 0 || $set->getReps() < 1) continue;
            $weight = $set->getWeight();
            $reps = $set->getReps();
            $entry = ['weight' => $weight, 'reps' => $reps, 'date' => $set->getSession()->getDate()->format('Y-m-d')];
            $e1rm = WorkoutSetLog::estimateOneRepMax($weight, $reps);
            foreach (['max_weight' => $weight, 'max_reps' => $reps, 'max_e1rm' => $e1rm] as $key => $value) {
                if ($value !== null && $value > $records[$key]) {
                    $records[$key] = $value;
                    $records[$key . '_set'] = $key === 'max_e1rm' ? $entry + ['e1rm' => $e1rm] : $entry;
                }
            }
        }
        return $records;
    }

    private function newRecordTypes(array $prior, array $current): array
    {
        return array_values(array_filter(['max_weight', 'max_reps', 'max_e1rm'],
            fn (string $type) => $prior[$type] > 0 && $current[$type] > $prior[$type]));
    }

    private function sessionPersonalRecords(WorkoutSession $session): array
    {
        if ($session->getStatus() !== 'completed') return [];
        $exerciseSets = [];
        foreach ($session->getSets() as $set) {
            $exerciseSets[$set->getExercise()->getId()->toRfc4122()][] = $set;
        }
        ksort($exerciseSets);
        $result = [];
        foreach ($exerciseSets as $exerciseId => $sets) {
            usort($sets, fn (WorkoutSetLog $a, WorkoutSetLog $b) => ($a->getSetNumber() <=> $b->getSetNumber()) ?: strcmp($a->getId()->toRfc4122(), $b->getId()->toRfc4122()));
            $prior = $this->records($this->recordSets($exerciseId, $session));
            $current = $this->records($sets);
            foreach ($this->newRecordTypes($prior, $current) as $type) {
                foreach ($sets as $set) {
                    if ($this->records([$set])[$type] !== $current[$type]) continue;
                    $result[] = [
                        'type' => $type, 'exercise_id' => $exerciseId, 'exercise_name' => $set->getExercise()->getName(),
                        'set_id' => $set->getId()->toRfc4122(), 'weight' => $set->getWeight(), 'reps' => $set->getReps(),
                        'value' => $current[$type], 'previous_value' => $prior[$type],
                    ];
                    break;
                }
            }
        }
        return $result;
    }

    private function parseSessionDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d))?$/D', $value)) return null;
        if (!checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) return null;
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
        } catch (\Exception) {
            return null;
        }
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private function parseJsonBody(Request $request): array|JsonResponse
    {
        if ($request->getContent() === '') return [];
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        return is_array($data) ? $data : $this->json(['error' => 'Invalid JSON body'], 400);
    }
}
