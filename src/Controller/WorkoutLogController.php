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
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'list_sessions', methods: ['GET'])]
    public function listSessions(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 25)));
        $offset = ($page - 1) * $limit;

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(WorkoutSession::class, 's')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $sessions = $this->em->createQueryBuilder()
            ->select('s', 'sl', 'e')
            ->from(WorkoutSession::class, 's')
            ->leftJoin('s.sets', 'sl')
            ->leftJoin('sl.exercise', 'e')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.date', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $includeSets = $request->query->getBoolean('include_sets', false);

        $result = [];
        foreach ($sessions as $session) {
            $sets = $session->getSets();
            $exerciseMap = [];
            $totalVolume = 0;

            foreach ($sets as $set) {
                $exId = $set->getExercise()->getId()->toRfc4122();
                if (!isset($exerciseMap[$exId])) {
                    $exerciseMap[$exId] = [
                        'exercise_id' => $exId,
                        'exercise_name' => $set->getExercise()->getName(),
                    ];
                }
                $totalVolume += $set->getWeight() * $set->getReps();
            }

            $routine = $session->getRoutine();

            $entry = [
                'id' => $session->getId()->toRfc4122(),
                'routine_id' => $routine?->getId()?->toRfc4122(),
                'routine_name' => $routine?->getName(),
                'date' => $session->getDate()->format('c'),
                'duration_minutes' => $session->getDurationMinutes(),
                'total_volume' => $totalVolume,
                'exercise_count' => count($exerciseMap),
                'set_count' => $sets->count(),
            ];

            if ($includeSets) {
                $mappedSets = [];
                foreach ($sets as $set) {
                    $exercise = $set->getExercise();
                    $mappedSets[] = [
                        'id' => $set->getId()->toRfc4122(),
                        'exercise_id' => $exercise->getId()->toRfc4122(),
                        'exercise_name' => $exercise->getName(),
                        'muscle_group' => $exercise->getMuscleGroup(),
                        'weight' => $set->getWeight(),
                        'reps' => $set->getReps(),
                        'completed' => $set->isCompleted(),
                        'set_type' => $set->getSetType(),
                        'duration_seconds' => $set->getDurationSeconds(),
                        'distance_km' => $set->getDistanceKm(),
                    ];
                }
                $entry['sets'] = $mappedSets;
            }

            $result[] = $entry;
        }

        $response = $this->json($result);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Per-Page', (string) $limit);

        return $response;
    }

    #[Route('/exercise-summaries', name: 'exercise_summaries', methods: ['POST'])]
    public function exerciseSummaries(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $exerciseIds = $data['exercise_ids'] ?? [];
        if (!is_array($exerciseIds) || empty($exerciseIds)) {
            return $this->json(['summaries' => (object) []]);
        }

        $summaries = [];
        foreach ($exerciseIds as $rawId) {
            $exId = trim((string) $rawId);
            if (!Uuid::isValid($exId)) {
                continue;
            }

            /** @var WorkoutSetLog[] $allSets */
            $allSets = $this->em->createQueryBuilder()
                ->select('sl', 's')
                ->from(WorkoutSetLog::class, 'sl')
                ->join('sl.session', 's')
                ->where('s.user = :user')
                ->andWhere('sl.exercise = :exId')
                ->andWhere('sl.completed = :completed')
                ->andWhere('sl.weight > 0')
                ->andWhere('sl.reps >= 1')
                ->setParameter('user', $user)
                ->setParameter('exId', $exId)
                ->setParameter('completed', true)
                ->orderBy('s.date', 'DESC')
                ->addOrderBy('s.id', 'DESC')
                ->addOrderBy('sl.id', 'ASC')
                ->getQuery()
                ->getResult();

            if (empty($allSets)) {
                $summaries[$exId] = [
                    'last_session' => null,
                    'records' => null,
                ];
                continue;
            }

            $lastSessionObj = $allSets[0]->getSession();
            $lastSessionId = $lastSessionObj->getId()->toRfc4122();
            $lastSessionDate = $lastSessionObj->getDate()->format('Y-m-d');

            $lastSessionSets = [];
            $setNumber = 1;
            foreach ($allSets as $set) {
                if ($set->getSession()->getId()->toRfc4122() === $lastSessionId) {
                    $lastSessionSets[] = [
                        'set_number' => $setNumber++,
                        'weight' => (float) $set->getWeight(),
                        'reps' => (int) $set->getReps(),
                        'completed' => (bool) $set->isCompleted(),
                        'set_type' => $set->getSetType(),
                    ];
                }
            }

            $maxWeight = 0.0;
            $maxWeightSet = null;
            $maxE1rm = 0.0;
            $maxE1rmSet = null;
            $maxReps = 0;
            $maxRepsSet = null;

            foreach ($allSets as $set) {
                // Warm-up sets should not count towards all-time PR records
                if ($set->getSetType() === 'warmup') {
                    continue;
                }

                $w = (float) $set->getWeight();
                $r = (int) $set->getReps();
                $e1rm = $r === 1 ? $w : round($w * (1.0 + $r / 30.0), 1);
                $d = $set->getSession()->getDate()->format('Y-m-d');

                if ($w > $maxWeight) {
                    $maxWeight = $w;
                    $maxWeightSet = ['weight' => $w, 'reps' => $r, 'date' => $d];
                }
                if ($e1rm > $maxE1rm) {
                    $maxE1rm = $e1rm;
                    $maxE1rmSet = ['weight' => $w, 'reps' => $r, 'e1rm' => $e1rm, 'date' => $d];
                }
                if ($r > $maxReps) {
                    $maxReps = $r;
                    $maxRepsSet = ['weight' => $w, 'reps' => $r, 'date' => $d];
                }
            }

            $summaries[$exId] = [
                'last_session' => [
                    'session_id' => $lastSessionId,
                    'date' => $lastSessionDate,
                    'sets' => $lastSessionSets,
                ],
                'records' => [
                    'max_weight' => $maxWeight,
                    'max_weight_set' => $maxWeightSet,
                    'max_e1rm' => $maxE1rm,
                    'max_e1rm_set' => $maxE1rmSet,
                    'max_reps' => $maxReps,
                    'max_reps_set' => $maxRepsSet,
                ],
            ];
        }

        return $this->json(['summaries' => $summaries]);
    }

    #[Route('/{sessionId}', name: 'get_session', methods: ['GET'])]
    public function getSession(string $sessionId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->em->getRepository(WorkoutSession::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }
        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $routine = $session->getRoutine();
        $sets = [];

        foreach ($session->getSets() as $set) {
            $exercise = $set->getExercise();
            $sets[] = [
                'id' => $set->getId()->toRfc4122(),
                'exercise_id' => $exercise->getId()->toRfc4122(),
                'exercise_name' => $exercise->getName(),
                'muscle_group' => $exercise->getMuscleGroup(),
                'weight' => $set->getWeight(),
                'reps' => $set->getReps(),
                'completed' => $set->isCompleted(),
                'set_type' => $set->getSetType(),
                'duration_seconds' => $set->getDurationSeconds(),
                'distance_km' => $set->getDistanceKm(),
            ];
        }

        return $this->json([
            'id' => $session->getId()->toRfc4122(),
            'routine_id' => $routine?->getId()?->toRfc4122(),
            'routine_name' => $routine?->getName(),
            'date' => $session->getDate()->format('c'),
            'duration_minutes' => $session->getDurationMinutes(),
            'sets' => $sets,
        ]);
    }

    #[Route('/{sessionId}', name: 'update_session', methods: ['PATCH'])]
    public function updateSession(string $sessionId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->em->getRepository(WorkoutSession::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }
        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (isset($data['duration_minutes'])) {
            $duration = filter_var($data['duration_minutes'], FILTER_VALIDATE_INT);
            if ($duration === false || $duration < 0) {
                return $this->json(['error' => 'duration_minutes must be a positive integer'], 400);
            }
            $session->setDurationMinutes($duration);
        }

        $this->em->flush();

        return $this->json(['message' => 'Session updated successfully']);
    }

    #[Route('/{sessionId}', name: 'delete_session', methods: ['DELETE'])]
    public function deleteSession(string $sessionId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->em->getRepository(WorkoutSession::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }
        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->em->remove($session);
        $this->em->flush();

        return $this->json(['message' => 'Session deleted successfully']);
    }

    #[Route('', name: 'start_session', methods: ['POST'])]
    public function startSession(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $session = new WorkoutSession();
        $session->setUser($user);

        if (isset($data['routine_id'])) {
            $routineId = trim((string) $data['routine_id']);
            if (!Uuid::isValid($routineId)) {
                return $this->json(['error' => 'Invalid routine_id format'], 400);
            }

            $routine = $this->em->getRepository(Routine::class)->find($routineId);
            if (!$routine) {
                return $this->json(['error' => 'Routine not found'], 404);
            }
            if ($routine->getUser() !== $user) {
                return $this->json(['error' => 'Forbidden'], 403);
            }

            $session->setRoutine($routine);
        }

        $this->em->persist($session);
        $this->em->flush();

        return $this->json([
            'message' => 'Workout session started', 
            'id' => $session->getId()->toRfc4122()
        ], 201);
    }

    #[Route('/{sessionId}/sets', name: 'log_set', methods: ['POST'])]
    public function logSet(string $sessionId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->em->getRepository(WorkoutSession::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $exerciseId = trim((string) ($data['exercise_id'] ?? ''));
        if (!Uuid::isValid($exerciseId)) {
            return $this->json(['error' => 'Missing or invalid exercise_id'], 400);
        }

        $exercise = $this->em->getRepository(Exercise::class)->find($exerciseId);
        if (!$exercise) {
            return $this->json(['error' => 'Exercise not found'], 404);
        }

        $isCardio = in_array($exercise->getTrackingType(), [Exercise::TRACKING_DISTANCE_DURATION, Exercise::TRACKING_DURATION], true)
            || strcasecmp((string) $exercise->getMuscleGroup(), 'Cardio') === 0;

        $reps = filter_var($data['reps'] ?? ($isCardio ? 0 : null), FILTER_VALIDATE_INT);
        $weight = filter_var($data['weight'] ?? ($isCardio ? 0.0 : null), FILTER_VALIDATE_FLOAT);

        if (!$isCardio) {
            if ($reps === false || $weight === false || $reps === null || $weight === null) {
                return $this->json(['error' => 'Missing required fields: exercise_id, weight, reps'], 400);
            }
            if ($reps < 1 || $reps > 1000) {
                return $this->json(['error' => 'reps must be between 1 and 1000'], 400);
            }
            if ($weight < 0 || $weight > 1000) {
                return $this->json(['error' => 'weight must be between 0 and 1000'], 400);
            }
        }

        $durationSeconds = filter_var($data['duration_seconds'] ?? $data['durationSeconds'] ?? null, FILTER_VALIDATE_INT);
        $distanceKm = filter_var($data['distance_km'] ?? $data['distanceKm'] ?? null, FILTER_VALIDATE_FLOAT);

        $newWeight = (float) ($weight !== false && $weight !== null ? $weight : 0.0);
        $newReps = (int) ($reps !== false && $reps !== null ? $reps : 0);
        $newE1rm = $newReps === 1 ? $newWeight : ($newReps > 1 ? round($newWeight * (1.0 + $newReps / 30.0), 1) : 0.0);

        $priorStats = $this->em->createQueryBuilder()
            ->select('MAX(sl.weight) as max_weight')
            ->from(WorkoutSetLog::class, 'sl')
            ->join('sl.session', 's')
            ->where('s.user = :user')
            ->andWhere('sl.exercise = :exId')
            ->andWhere('sl.completed = :completed')
            ->andWhere('sl.weight > 0')
            ->setParameter('user', $user)
            ->setParameter('exId', $exerciseId)
            ->setParameter('completed', true)
            ->getQuery()
            ->getSingleResult();

        $priorMaxWeight = $priorStats['max_weight'] !== null ? (float) $priorStats['max_weight'] : null;

        $priorSets = $this->em->createQueryBuilder()
            ->select('sl.weight, sl.reps')
            ->from(WorkoutSetLog::class, 'sl')
            ->join('sl.session', 's')
            ->where('s.user = :user')
            ->andWhere('sl.exercise = :exId')
            ->andWhere('sl.completed = :completed')
            ->andWhere('sl.weight > 0')
            ->andWhere('sl.reps >= 1')
            ->setParameter('user', $user)
            ->setParameter('exId', $exerciseId)
            ->setParameter('completed', true)
            ->getQuery()
            ->getResult();

        $priorMaxE1rm = null;
        foreach ($priorSets as $ps) {
            $pw = (float) $ps['weight'];
            $pr = (int) $ps['reps'];
            $pe = $pr === 1 ? $pw : round($pw * (1.0 + $pr / 30.0), 1);
            if ($priorMaxE1rm === null || $pe > $priorMaxE1rm) {
                $priorMaxE1rm = $pe;
            }
        }

        $setType = trim((string) ($data['set_type'] ?? 'normal'));
        if (!in_array($setType, ['normal', 'warmup', 'drop_set', 'failure'], true)) {
            $setType = 'normal';
        }

        $isPr = false;
        $prTypes = [];
        // Warm-up sets should never trigger or count as personal records
        if ($setType !== 'warmup' && $newWeight > 0) {
            if ($priorMaxWeight !== null && $newWeight > $priorMaxWeight) {
                $isPr = true;
                $prTypes[] = 'max_weight';
            }
            if ($priorMaxE1rm !== null && $newE1rm > $priorMaxE1rm) {
                $isPr = true;
                $prTypes[] = 'max_e1rm';
            }
        }

        $setLog = new WorkoutSetLog();
        $setLog->setExercise($exercise);
        $setLog->setWeight($newWeight);
        $setLog->setReps($newReps);
        $setLog->setCompleted(true);
        $setLog->setSetType($setType);
        $setLog->setDurationSeconds($durationSeconds !== false ? $durationSeconds : null);
        $setLog->setDistanceKm($distanceKm !== false ? $distanceKm : null);

        $session->addSet($setLog);
        $this->em->persist($setLog);

        $this->em->flush();

        return $this->json([
            'message' => 'Set logged successfully',
            'setId' => $setLog->getId()->toRfc4122(),
            'set_type' => $setLog->getSetType(),
            'duration_seconds' => $setLog->getDurationSeconds(),
            'distance_km' => $setLog->getDistanceKm(),
            'is_pr' => $isPr,
            'pr_types' => $prTypes,
            'pr_label' => $isPr ? '¡Nuevo récord personal!' : null,
            'previous_max_weight' => $priorMaxWeight,
            'new_weight' => $newWeight,
            'previous_max_e1rm' => $priorMaxE1rm,
            'new_e1rm' => $newE1rm,
        ], 201);
    }

    #[Route('/{sessionId}/sets/{setId}', name: 'delete_set', methods: ['DELETE'])]
    public function deleteSet(string $sessionId, string $setId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->em->getRepository(WorkoutSession::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $set = $this->em->getRepository(WorkoutSetLog::class)->find($setId);
        if (!$set || $set->getSession() !== $session) {
            return $this->json(['error' => 'Set not found in this session'], 404);
        }

        $session->removeSet($set);
        $this->em->remove($set);
        $this->em->flush();

        return $this->json(['message' => 'Set deleted successfully']);
    }

    #[Route('/{sessionId}/sets/{setId}', name: 'update_set', methods: ['PATCH'])]
    public function updateSet(string $sessionId, string $setId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->em->getRepository(WorkoutSession::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $set = $this->em->getRepository(WorkoutSetLog::class)->find($setId);
        if (!$set || $set->getSession() !== $session) {
            return $this->json(['error' => 'Set not found in this session'], 404);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (array_key_exists('weight', $data)) {
            $weight = filter_var($data['weight'], FILTER_VALIDATE_FLOAT);
            if ($weight === false || $weight < 0 || $weight > 1000) {
                return $this->json(['error' => 'weight must be between 0 and 1000'], 400);
            }
            $set->setWeight((float) $weight);
        }

        if (array_key_exists('reps', $data)) {
            $reps = filter_var($data['reps'], FILTER_VALIDATE_INT);
            if ($reps === false || $reps < 0 || $reps > 1000) {
                return $this->json(['error' => 'reps must be between 0 and 1000'], 400);
            }
            $set->setReps((int) $reps);
        }

        if (array_key_exists('completed', $data)) {
            $set->setCompleted((bool) $data['completed']);
        }

        if (array_key_exists('set_type', $data)) {
            $setType = trim((string) $data['set_type']);
            if (in_array($setType, ['normal', 'warmup', 'drop_set', 'failure'], true)) {
                $set->setSetType($setType);
            }
        }

        if (array_key_exists('duration_seconds', $data) || array_key_exists('durationSeconds', $data)) {
            $dur = filter_var($data['duration_seconds'] ?? $data['durationSeconds'], FILTER_VALIDATE_INT);
            $set->setDurationSeconds($dur !== false ? $dur : null);
        }

        if (array_key_exists('distance_km', $data) || array_key_exists('distanceKm', $data)) {
            $dist = filter_var($data['distance_km'] ?? $data['distanceKm'], FILTER_VALIDATE_FLOAT);
            $set->setDistanceKm($dist !== false ? $dist : null);
        }

        $this->em->flush();

        return $this->json([
            'message' => 'Set updated successfully',
            'id' => $set->getId()->toRfc4122(),
            'weight' => $set->getWeight(),
            'reps' => $set->getReps(),
            'completed' => $set->isCompleted(),
            'set_type' => $set->getSetType(),
            'duration_seconds' => $set->getDurationSeconds(),
            'distance_km' => $set->getDistanceKm(),
        ]);
    }

    /**
     * @return array<mixed>|JsonResponse
     */
    private function parseJsonBody(Request $request): array|JsonResponse
    {
        if ($request->getContent() === '') {
            return [];
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        return $data;
    }
}
