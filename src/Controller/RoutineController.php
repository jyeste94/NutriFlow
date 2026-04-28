<?php

namespace App\Controller;

use App\Entity\Exercise;
use App\Entity\Routine;
use App\Entity\RoutineExercise;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/routines', name: 'api_routines_')]
class RoutineController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'get_all', methods: ['GET'])]
    public function getUserRoutines(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $firebaseUid = $user->getUserIdentifier();
        $user = $this->em->getRepository(User::class)->findOneBy(['firebaseUid' => $firebaseUid]);
        if (!$user) {
            return $this->json(['error' => 'User not found'], 401);
        }

        $routine = $this->em->getRepository(Routine::class)->find($id);
        if (!$routine) {
            return $this->json(['error' => 'Routine not found'], 404);
        }
        if ($routine->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $exercises = [];
        foreach ($routine->getRoutineExercises() as $re) {
            $exercise = $re->getExercise();
            $exercises[] = [
                'id' => $re->getId()->toRfc4122(),
                'exercise' => [
                    'id' => $exercise->getId()->toRfc4122(),
                    'name' => $exercise->getName(),
                    'muscleGroup' => $exercise->getMuscleGroup(),
                    'equipment' => $exercise->getEquipment(),
                    'description' => $exercise->getDescription(),
                    'gifUrl' => $exercise->getGifUrl(),
                    'videoUrl' => $exercise->getVideoUrl(),
                ],
                'sets' => $re->getSets(),
                'reps' => $re->getReps(),
                'restSeconds' => $re->getRestSeconds(),
                'orderIndex' => $re->getOrderIndex(),
            ];
        }

        return $this->json([
            'id' => $routine->getId()->toRfc4122(),
            'name' => $routine->getName(),
            'daysOfWeek' => $routine->getDaysOfWeek(),
            'exercises' => $exercises,
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createRoutine(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // Refrescar usuario por firebaseUid
        $firebaseUid = $user->getUserIdentifier();
        $userRow = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM users WHERE firebase_uid = ?', [$firebaseUid]
        );
        if (!$userRow) {
            return $this->json(['error' => 'User not found in database'], 401);
        }
        $userId = $userRow['id'];

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            return $this->json(['error' => 'Routine name is required'], 400);
        }

        $routine = new Routine();
        $routine->setUser($user);
        $routine->setName($name);

        if (array_key_exists('daysOfWeek', $data)) {
            if (!is_array($data['daysOfWeek'])) {
                return $this->json(['error' => 'daysOfWeek must be an array of integers (1-7)'], 400);
            }

            $normalizedDays = $this->normalizeDaysOfWeek($data['daysOfWeek']);
            if ($normalizedDays === null) {
                return $this->json(['error' => 'daysOfWeek must contain unique integers between 1 and 7'], 400);
            }

            $routine->setDaysOfWeek($normalizedDays);
        }

        $preparedExercises = [];
        if (array_key_exists('exercises', $data)) {
            if (!is_array($data['exercises'])) {
                return $this->json(['error' => 'exercises must be an array'], 400);
            }

            foreach ($data['exercises'] as $index => $exData) {
                if (!is_array($exData)) {
                    return $this->json(['error' => "Invalid exercise payload at index $index"], 400);
                }

                $exerciseId = trim((string) ($exData['exercise_id'] ?? ''));
                $sets = filter_var($exData['sets'] ?? 3, FILTER_VALIDATE_INT);
                $reps = filter_var($exData['reps'] ?? 10, FILTER_VALIDATE_INT);
                $restSeconds = filter_var($exData['restSeconds'] ?? 60, FILTER_VALIDATE_INT);

                if (!Uuid::isValid($exerciseId)) {
                    return $this->json(['error' => "Invalid exercise_id at index $index"], 400);
                }
                if ($sets === false || $sets < 1 || $sets > 20) {
                    return $this->json(['error' => "Invalid sets at index $index (1-20)"], 400);
                }
                if ($reps === false || $reps < 1 || $reps > 1000) {
                    return $this->json(['error' => "Invalid reps at index $index (1-1000)"], 400);
                }
                if ($restSeconds === false || $restSeconds < 0 || $restSeconds > 3600) {
                    return $this->json(['error' => "Invalid restSeconds at index $index (0-3600)"], 400);
                }

                $preparedExercises[] = [
                    'exercise_id' => $exerciseId,
                    'sets' => (int) $sets,
                    'reps' => (int) $reps,
                    'restSeconds' => (int) $restSeconds,
                    'orderIndex' => (int) $index,
                ];
            }
        }

        $conn = $this->em->getConnection();

        try {
            // Insertar rutina con SQL directo
            $routineId = $conn->fetchOne('SELECT UUID()');
            $daysJson = isset($data['daysOfWeek']) ? json_encode($data['daysOfWeek']) : null;

            $conn->executeStatement(
                'INSERT INTO routines (id, user_id, name, days_of_week) VALUES (?, ?, ?, ?)',
                [$routineId, $userId, $name, $daysJson]
            );

            // Insertar ejercicios
            foreach ($preparedExercises as $ex) {
                $conn->executeStatement(
                    'INSERT INTO routine_exercises (id, routine_id, exercise_id, sets, reps, rest_seconds, order_index) VALUES (UUID(), ?, ?, ?, ?, ?, ?)',
                    [$routineId, $ex['exercise_id'], $ex['sets'], $ex['reps'], $ex['restSeconds'], $ex['orderIndex']]
                );
            }

            return $this->json(['message' => 'Routine created successfully', 'id' => $routineId], 201);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Could not create routine: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function updateRoutine(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $routine = $this->em->getRepository(Routine::class)->find($id);
        if (!$routine) {
            return $this->json(['error' => 'Routine not found'], 404);
        }
        if ($routine->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) > 255) {
                return $this->json(['error' => 'Routine name is required'], 400);
            }
            $routine->setName($name);
        }

        if (array_key_exists('daysOfWeek', $data)) {
            if (!is_array($data['daysOfWeek'])) {
                return $this->json(['error' => 'daysOfWeek must be an array of integers (1-7)'], 400);
            }
            $normalizedDays = $this->normalizeDaysOfWeek($data['daysOfWeek']);
            if ($normalizedDays === null) {
                return $this->json(['error' => 'daysOfWeek must contain unique integers between 1 and 7'], 400);
            }
            $routine->setDaysOfWeek($normalizedDays);
        }

        $preparedExercises = null;
        if (array_key_exists('exercises', $data)) {
            if (!is_array($data['exercises'])) {
                return $this->json(['error' => 'exercises must be an array'], 400);
            }

            $preparedExercises = [];
            foreach ($data['exercises'] as $index => $exData) {
                if (!is_array($exData)) {
                    return $this->json(['error' => "Invalid exercise payload at index $index"], 400);
                }

                $exerciseId = trim((string) ($exData['exercise_id'] ?? ''));
                $sets = filter_var($exData['sets'] ?? 3, FILTER_VALIDATE_INT);
                $reps = filter_var($exData['reps'] ?? 10, FILTER_VALIDATE_INT);
                $restSeconds = filter_var($exData['restSeconds'] ?? 60, FILTER_VALIDATE_INT);

                if (!Uuid::isValid($exerciseId)) {
                    return $this->json(['error' => "Invalid exercise_id at index $index"], 400);
                }
                if ($sets === false || $sets < 1 || $sets > 20) {
                    return $this->json(['error' => "Invalid sets at index $index (1-20)"], 400);
                }
                if ($reps === false || $reps < 1 || $reps > 1000) {
                    return $this->json(['error' => "Invalid reps at index $index (1-1000)"], 400);
                }
                if ($restSeconds === false || $restSeconds < 0 || $restSeconds > 3600) {
                    return $this->json(['error' => "Invalid restSeconds at index $index (0-3600)"], 400);
                }

                $exercise = $this->em->getRepository(Exercise::class)->createQueryBuilder('e')->where('e.id = :id')->setParameter('id', Uuid::fromString($exerciseId))->getQuery()->getOneOrNullResult();
                if (!$exercise instanceof Exercise) {
                    return $this->json(['error' => "Exercise not found at index $index (exercise_id: $exerciseId)"], 400);
                }

                $preparedExercises[] = [
                    'exercise' => $exercise,
                    'sets' => (int) $sets,
                    'reps' => (int) $reps,
                    'restSeconds' => (int) $restSeconds,
                    'orderIndex' => (int) $index,
                ];
            }
        }

        if (is_array($preparedExercises)) {
            foreach ($routine->getRoutineExercises() as $existing) {
                $this->em->remove($existing);
            }
            $routine->getRoutineExercises()->clear();

            foreach ($preparedExercises as $preparedExercise) {
                $routineExercise = new RoutineExercise();
                $routineExercise->setRoutine($routine);
                $routineExercise->setExercise($preparedExercise['exercise']);
                $routineExercise->setSets($preparedExercise['sets']);
                $routineExercise->setReps($preparedExercise['reps']);
                $routineExercise->setRestSeconds($preparedExercise['restSeconds']);
                $routineExercise->setOrderIndex($preparedExercise['orderIndex']);
                $this->em->persist($routineExercise);
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Could not update routine: ' . $e->getMessage()], 500);
        }

        return $this->json(['message' => 'Routine updated successfully', 'id' => $routine->getId()->toRfc4122()]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function deleteRoutine(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $routine = $this->em->getRepository(Routine::class)->find($id);

        if (!$routine) {
            return $this->json(['error' => 'Routine not found'], 404);
        }

        if ($routine->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->em->remove($routine);
        $this->em->flush();

        return $this->json(['message' => 'Routine deleted successfully']);
    }

    private const DAY_TOKEN_PREFIX = 'athlos-json:';

    /**
     * @param array<mixed> $days
     * @return array<mixed>|null
     */
    private function normalizeDaysOfWeek(array $days): ?array
    {
        if (count($days) > 7) return null;

        // Check if all values are integers (simple day numbers)
        $allInts = true;
        $allStrings = true;
        foreach ($days as $day) {
            if (!is_int($day)) $allInts = false;
            if (!is_string($day)) $allStrings = false;
        }

        if ($allInts) {
            $normalized = [];
            foreach ($days as $day) {
                $parsed = filter_var($day, FILTER_VALIDATE_INT);
                if ($parsed === false || $parsed < 1 || $parsed > 7) return null;
                $normalized[] = $parsed;
            }
            $unique = array_values(array_unique($normalized));
            if (count($unique) !== count($normalized)) return null;
            sort($unique);
            return $unique;
        }

        if ($allStrings) {
            // Pass through athlos-json descriptors as-is (already structured)
            return $days;
        }

        return null; // Mixed types not allowed
    }
}
