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
        $reps = filter_var($data['reps'] ?? null, FILTER_VALIDATE_INT);
        $weight = filter_var($data['weight'] ?? null, FILTER_VALIDATE_FLOAT);

        if (!Uuid::isValid($exerciseId) || $reps === false || $weight === false) {
             return $this->json(['error' => 'Missing required fields: exercise_id, weight, reps'], 400);
        }
        if ($reps < 1 || $reps > 1000) {
            return $this->json(['error' => 'reps must be between 1 and 1000'], 400);
        }
        if ($weight < 0 || $weight > 1000) {
            return $this->json(['error' => 'weight must be between 0 and 1000'], 400);
        }

        $exercise = $this->em->getRepository(Exercise::class)->find($exerciseId);
        if (!$exercise) {
            return $this->json(['error' => 'Exercise not found'], 404);
        }

        $setLog = new WorkoutSetLog();
        $setLog->setExercise($exercise);
        $setLog->setWeight((float) $weight);
        $setLog->setReps((int) $reps);
        $setLog->setCompleted(true);

        $session->addSet($setLog);
        $this->em->persist($setLog);

        $this->em->flush();

        return $this->json(['message' => 'Set logged successfully', 'setId' => $setLog->getId()->toRfc4122()], 201);
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
