<?php

namespace App\Controller;

use App\Entity\MealDiary;
use App\Entity\MealEntry;
use App\Entity\Serving;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/diaries', name: 'api_diaries_')]
class DiaryController extends AbstractController
{
    private const ALLOWED_MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('/{date}', name: 'get', methods: ['GET'])]
    public function getDiary(string $date): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $diaryDate = $this->parseIsoDate($date);
        if ($diaryDate === null) {
            return $this->json(['error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
        }

        $diary = $this->em->getRepository(MealDiary::class)->findOneBy([
            'user' => $user,
            'date' => $diaryDate
        ]);

        if (!$diary) {
            return $this->json([
                'date' => $diaryDate->format('Y-m-d'),
                'totalCalories' => 0,
                'totalProteins' => 0,
                'totalCarbs' => 0,
                'totalFats' => 0,
                'entries' => []
            ]);
        }

        $entries = [];
        foreach ($diary->getEntries() as $entry) {
            $serving = $entry->getServing();
            $food = $serving->getFood();
            
            $entries[] = [
                'id' => $entry->getId()->toRfc4122(),
                'mealType' => $entry->getMealType(),
                'multiplier' => $entry->getMultiplier(),
                'serving' => [
                    'id' => $serving->getId()->toRfc4122(),
                    'description' => $serving->getDescription(),
                    'calories' => $serving->getCalories(),
                    'proteins' => $serving->getProteins(),
                    'carbs' => $serving->getCarbs(),
                    'fats' => $serving->getFats(),
                ],
                'food' => [
                    'id' => $food->getId()->toRfc4122(),
                    'name' => $food->getName(),
                    'brand' => $food->getBrand(),
                ]
            ];
        }

        return $this->json([
            'id' => $diary->getId()->toRfc4122(),
            'date' => $diary->getDate()->format('Y-m-d'),
            'totalCalories' => $diary->getTotalCalories(),
            'totalProteins' => $diary->getTotalProteins(),
            'totalCarbs' => $diary->getTotalCarbs(),
            'totalFats' => $diary->getTotalFats(),
            'entries' => $entries
        ]);
    }

    #[Route('/{date}/entries', name: 'add_entry', methods: ['POST'])]
    public function addEntry(string $date, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $diaryDate = $this->parseIsoDate($date);
        if ($diaryDate === null) {
            return $this->json(['error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $servingId = (string) ($data['serving_id'] ?? '');
        $mealType = strtolower((string) ($data['mealType'] ?? ''));
        $multiplier = filter_var($data['multiplier'] ?? null, FILTER_VALIDATE_FLOAT);

        if (!Uuid::isValid($servingId) || $mealType === '' || $multiplier === false) {
            return $this->json(['error' => 'Missing required fields: serving_id, mealType, multiplier'], 400);
        }
        if (!in_array($mealType, self::ALLOWED_MEAL_TYPES, true)) {
            return $this->json(['error' => 'mealType must be one of: breakfast, lunch, dinner, snack'], 400);
        }
        if ($multiplier <= 0 || $multiplier > 100) {
            return $this->json(['error' => 'multiplier must be greater than 0 and less than or equal to 100'], 400);
        }

        $serving = $this->em->getRepository(Serving::class)->find($servingId);
        if (!$serving) {
             return $this->json(['error' => 'Serving not found'], 404);
        }

        // Get or Create Diary
        $diary = $this->em->getRepository(MealDiary::class)->findOneBy([
            'user' => $user,
            'date' => $diaryDate
        ]);

        if (!$diary) {
            $diary = new MealDiary();
            $diary->setUser($user);
            $diary->setDate($diaryDate);
            $this->em->persist($diary);
        }

        $entry = new MealEntry();
        $entry->setServing($serving);
        $entry->setMealType($mealType);
        $entry->setMultiplier((float) $multiplier);
        
        $diary->addEntry($entry);
        $this->em->persist($entry);
        
        $this->em->flush();

        return $this->json(['message' => 'Entry added successfully', 'entryId' => $entry->getId()->toRfc4122()], 201);
    }

    #[Route('/entries/{id}', name: 'delete_entry', methods: ['DELETE'])]
    public function deleteEntry(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $entry = $this->em->getRepository(MealEntry::class)->find($id);
        if (!$entry) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        $diary = $entry->getDiary();
        // Security check: ensure diary belongs to requested user
        if ($diary->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $diary->removeEntry($entry);
        $this->em->remove($entry);
        $this->em->flush();

        return $this->json(['message' => 'Entry deleted successfully']);
    }

    private function parseIsoDate(string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if (!$parsed) {
            return null;
        }

        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
