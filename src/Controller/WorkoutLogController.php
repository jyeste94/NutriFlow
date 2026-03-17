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
            $routineId = (string) $data['routine_id'];
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

        $exerciseId = (string) ($data['exercise_id'] ?? '');
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
