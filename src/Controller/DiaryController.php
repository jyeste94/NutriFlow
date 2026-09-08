<?php

namespace App\Controller;

use App\Entity\Food;
use App\Entity\MealDiary;
use App\Entity\MealEntry;
use App\Entity\SavedMeal;
use App\Entity\Serving;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/diaries', name: 'api_diaries_')]
class DiaryController extends AbstractController
{
    private const ALLOWED_MEAL_TYPES = ['breakfast', 'almuerzo', 'lunch', 'merienda', 'dinner', 'snack'];

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'list_range', methods: ['GET'])]
    public function getDiariesRange(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        if (!$fromStr || !$toStr) {
            return $this->json(['error' => 'Query parameters "from" and "to" (YYYY-MM-DD) are required'], 400);
        }

        $fromDate = $this->parseIsoDate($fromStr);
        $toDate = $this->parseIsoDate($toStr);

        if ($fromDate === null || $toDate === null) {
            return $this->json(['error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
        }

        if ($fromDate > $toDate) {
            return $this->json(['error' => '"from" date must be earlier than or equal to "to" date'], 400);
        }

        $diaries = $this->em->createQueryBuilder()
            ->select('d')
            ->from(MealDiary::class, 'd')
            ->where('d.user = :user')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        /** @var MealDiary $diary */
        foreach ($diaries as $diary) {
            $result[] = [
                'id' => $diary->getId()?->toRfc4122(),
                'date' => $diary->getDate()?->format('Y-m-d'),
                'totalCalories' => $diary->getTotalCalories(),
                'totalProteins' => $diary->getTotalProteins(),
                'totalCarbs' => $diary->getTotalCarbs(),
                'totalFats' => $diary->getTotalFats(),
                'entriesCount' => $diary->getEntries()->count(),
            ];
        }

        return $this->json($result);
    }

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
            $food = $serving?->getFood();
            
            $entries[] = [
                'id' => $entry->getId()?->toRfc4122(),
                'mealType' => $entry->getMealType(),
                'multiplier' => $entry->getMultiplier(),
                'serving' => $serving ? [
                    'id' => $serving->getId()?->toRfc4122(),
                    'description' => $serving->getDescription(),
                    'calories' => $serving->getCalories(),
                    'proteins' => $serving->getProteins(),
                    'carbs' => $serving->getCarbs(),
                    'fats' => $serving->getFats(),
                ] : null,
                'food' => $food ? [
                    'id' => $food->getId()?->toRfc4122(),
                    'name' => $food->getName(),
                    'brand' => $food->getBrand(),
                ] : null
            ];
        }

        return $this->json([
            'id' => $diary->getId()?->toRfc4122(),
            'date' => $diary->getDate()?->format('Y-m-d'),
            'totalCalories' => $diary->getTotalCalories(),
            'totalProteins' => $diary->getTotalProteins(),
            'totalCarbs' => $diary->getTotalCarbs(),
            'totalFats' => $diary->getTotalFats(),
            'entries' => $entries
        ]);
    }

    #[Route('/{targetDate}/copy-meal', name: 'copy_meal', methods: ['POST'])]
    public function copyMeal(string $targetDate, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $targetDateObj = $this->parseIsoDate($targetDate);
        if ($targetDateObj === null) {
            return $this->json(['error' => 'Invalid target date format. Use YYYY-MM-DD'], 400);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $sourceDateStr = (string) ($data['sourceDate'] ?? '');
        $mealType = strtolower((string) ($data['mealType'] ?? ''));

        $sourceDateObj = $this->parseIsoDate($sourceDateStr);
        if ($sourceDateObj === null) {
            return $this->json(['error' => 'Invalid source date format. Use YYYY-MM-DD'], 400);
        }

        if (!in_array($mealType, self::ALLOWED_MEAL_TYPES, true)) {
            return $this->json(['error' => 'mealType must be one of: ' . implode(', ', self::ALLOWED_MEAL_TYPES)], 400);
        }

        $sourceDiary = $this->em->getRepository(MealDiary::class)->findOneBy([
            'user' => $user,
            'date' => $sourceDateObj
        ]);

        if (!$sourceDiary) {
            return $this->json(['error' => 'No diary entries found on source date'], 404);
        }

        $sourceEntries = [];
        foreach ($sourceDiary->getEntries() as $entry) {
            if ($entry->getMealType() === $mealType) {
                $sourceEntries[] = $entry;
            }
        }

        if (empty($sourceEntries)) {
            return $this->json(['error' => 'No entries found for mealType: ' . $mealType], 404);
        }

        // Target diary
        $targetDiary = $this->em->getRepository(MealDiary::class)->findOneBy([
            'user' => $user,
            'date' => $targetDateObj
        ]);

        if (!$targetDiary) {
            $targetDiary = new MealDiary();
            $targetDiary->setUser($user);
            $targetDiary->setDate($targetDateObj);
            $this->em->persist($targetDiary);
        }

        $createdEntries = [];
        foreach ($sourceEntries as $sourceEntry) {
            $newEntry = new MealEntry();
            $newEntry->setServing($sourceEntry->getServing());
            $newEntry->setMealType($sourceEntry->getMealType());
            $newEntry->setMultiplier($sourceEntry->getMultiplier());

            $targetDiary->addEntry($newEntry);
            $this->em->persist($newEntry);
            $createdEntries[] = $newEntry;
        }

        $this->em->flush();

        return $this->json([
            'message' => 'Meal copied successfully',
            'copiedCount' => count($createdEntries),
            'targetDiary' => [
                'id' => $targetDiary->getId()?->toRfc4122(),
                'date' => $targetDiary->getDate()?->format('Y-m-d'),
                'totalCalories' => $targetDiary->getTotalCalories(),
            ]
        ], 201);
    }

    #[Route('/{date}/saved-meals/{id}', name: 'add_saved_meal', methods: ['POST'])]
    public function addSavedMeal(string $date, string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $diaryDate = $this->parseIsoDate($date);
        if ($diaryDate === null) {
            return $this->json(['error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
        }

        $savedMeal = $this->em->getRepository(SavedMeal::class)->findOneBy([
            'id' => $id,
            'user' => $user,
        ]);

        if (!$savedMeal) {
            return $this->json(['error' => 'Saved meal not found'], 404);
        }

        $body = [];
        if ($request->getContent() !== '') {
            try {
                $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->json(['error' => 'Invalid JSON body'], 400);
            }
        }

        $mealType = strtolower((string) ($body['mealType'] ?? $savedMeal->getPreferredMealType() ?? 'snack'));
        if (!in_array($mealType, self::ALLOWED_MEAL_TYPES, true)) {
            return $this->json(['error' => 'mealType must be one of: ' . implode(', ', self::ALLOWED_MEAL_TYPES)], 400);
        }

        $diary = $this->em->getRepository(MealDiary::class)->findOneBy([
            'user' => $user,
            'date' => $diaryDate,
        ]);

        if (!$diary) {
            $diary = new MealDiary();
            $diary->setUser($user);
            $diary->setDate($diaryDate);
            $this->em->persist($diary);
        }

        $addedCount = 0;
        foreach ($savedMeal->getItems() as $item) {
            $serving = $item->getServing();

            // If item has no serving reference, create a custom food and serving on the fly
            if (!$serving) {
                $customFood = new Food();
                $customFood->setUser($user);
                $customFood->setName($item->getName());
                $customFood->setBrand($item->getBrand());
                $customFood->setExternalId('saved_item_' . uniqid('', true));
                $customFood->setLastFetchedAt(new \DateTimeImmutable());
                $customFood->setCreatedAt(new \DateTimeImmutable());
                $customFood->setUpdatedAt(new \DateTimeImmutable());

                $serving = new Serving();
                $serving->setFood($customFood);
                $serving->setDescription($item->getAmountGrams() > 0 ? $item->getAmountGrams() . 'g' : '1 ración');
                $serving->setCalories($item->getCalories());
                $serving->setProteins($item->getProteins());
                $serving->setCarbs($item->getCarbs());
                $serving->setFats($item->getFats());
                $serving->setAmount($item->getAmountGrams() > 0 ? $item->getAmountGrams() : 100);
                $serving->setUnit('g');

                $customFood->addServing($serving);
                $this->em->persist($customFood);
                $this->em->persist($serving);
            }

            $entry = new MealEntry();
            $entry->setServing($serving);
            $entry->setMealType($mealType);
            $entry->setMultiplier(1.0);

            $diary->addEntry($entry);
            $this->em->persist($entry);
            $addedCount++;
        }

        $this->em->flush();

        return $this->json([
            'message' => 'Saved meal added successfully',
            'entriesAdded' => $addedCount,
            'diary' => [
                'id' => $diary->getId()?->toRfc4122(),
                'date' => $diary->getDate()?->format('Y-m-d'),
                'totalCalories' => $diary->getTotalCalories(),
            ]
        ], 201);
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

        $mealType = strtolower((string) ($data['mealType'] ?? ''));
        if (!in_array($mealType, self::ALLOWED_MEAL_TYPES, true)) {
            return $this->json(['error' => 'mealType must be one of: ' . implode(', ', self::ALLOWED_MEAL_TYPES)], 400);
        }

        $servingId = (string) ($data['serving_id'] ?? '');
        $multiplier = filter_var($data['multiplier'] ?? 1.0, FILTER_VALIDATE_FLOAT);

        if ($multiplier === false || $multiplier <= 0 || $multiplier > 100) {
            return $this->json(['error' => 'multiplier must be greater than 0 and less than or equal to 100'], 400);
        }

        $serving = null;

        // Case 1: Standard Serving ID provided
        if ($servingId !== '' && Uuid::isValid($servingId)) {
            $serving = $this->em->getRepository(Serving::class)->find($servingId);
            if (!$serving) {
                return $this->json(['error' => 'Serving not found'], 404);
            }
        } elseif (!empty($data['name']) && isset($data['calories'])) {
            // Case 2: Inline custom food entry
            $foodName = trim((string) $data['name']);
            if ($foodName === '') {
                return $this->json(['error' => 'Food name cannot be empty'], 400);
            }

            $calories = (float) ($data['calories'] ?? 0);
            $proteins = (float) ($data['proteins'] ?? 0);
            $carbs = (float) ($data['carbs'] ?? 0);
            $fats = (float) ($data['fats'] ?? 0);
            $amountGrams = (float) ($data['amountGrams'] ?? 100);

            $customFood = new Food();
            $customFood->setUser($user);
            $customFood->setName($foodName);
            $customFood->setBrand($data['brand'] ?? null);
            $customFood->setExternalId('inline_' . uniqid('', true));
            $customFood->setLastFetchedAt(new \DateTimeImmutable());
            $customFood->setCreatedAt(new \DateTimeImmutable());
            $customFood->setUpdatedAt(new \DateTimeImmutable());

            $serving = new Serving();
            $serving->setFood($customFood);
            $serving->setDescription($amountGrams > 0 ? $amountGrams . 'g' : '1 ración');
            $serving->setCalories($calories);
            $serving->setProteins($proteins);
            $serving->setCarbs($carbs);
            $serving->setFats($fats);
            $serving->setAmount($amountGrams > 0 ? $amountGrams : 100);
            $serving->setUnit('g');

            $customFood->addServing($serving);
            $this->em->persist($customFood);
            $this->em->persist($serving);
            $multiplier = 1.0;
        } else {
            return $this->json(['error' => 'Missing required fields: serving_id (or name + calories), mealType'], 400);
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

        return $this->json([
            'message' => 'Entry added successfully',
            'entryId' => $entry->getId()?->toRfc4122(),
            'diary' => [
                'id' => $diary->getId()?->toRfc4122(),
                'date' => $diary->getDate()?->format('Y-m-d'),
                'totalCalories' => $diary->getTotalCalories(),
            ]
        ], 201);
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
