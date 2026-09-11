<?php

namespace App\Service;

use App\Entity\Food;
use App\Entity\Serving;
use App\Repository\FoodRepository;
use Doctrine\ORM\EntityManagerInterface;

class FoodService
{
    private const CACHE_TTL_DAYS = 30;
    private const SEARCH_PAGE_SIZE = 20;

    public function __construct(
        private FatSecretScraperService $scraper,
        private FoodRepository $foodRepo,
        private EntityManagerInterface $em
    ) {}

    public function search(string $query, int $page = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $page = max(0, $page);
        $offset = $page * self::SEARCH_PAGE_SIZE;

        $localFoods = $this->foodRepo->searchByNameOrBrand($query, self::SEARCH_PAGE_SIZE, $offset);
        $foodsByExternalId = [];
        foreach ($localFoods as $localFood) {
            $externalId = $localFood->getExternalId();
            if ($externalId !== null) {
                $foodsByExternalId[$externalId] = $localFood;
            }
        }

        $resultFoods = $localFoods;
        if (count($localFoods) < self::SEARCH_PAGE_SIZE) {
            try {
                $remoteResults = $this->scraper->search($query, $page);
            } catch (\Throwable) {
                $remoteResults = [];
            }

            foreach ($remoteResults as $remoteResult) {
                $food = $this->upsertFromRemoteSearch($remoteResult);
                if (!$food) {
                    continue;
                }

                $externalId = $food->getExternalId();
                if ($externalId === null || isset($foodsByExternalId[$externalId])) {
                    continue;
                }

                $foodsByExternalId[$externalId] = $food;
                $resultFoods[] = $food;

                if (count($resultFoods) >= self::SEARCH_PAGE_SIZE) {
                    break;
                }
            }

            $this->em->flush();
        }

        return array_map(fn (Food $food) => $this->formatFoodArray($food), $resultFoods);
    }

    public function getFoodDetails(string $id): ?array
    {
        $food = $this->foodRepo->findOneBy(['id' => $id]);
        if (!$food) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $lastFetchedAt = $food->getLastFetchedAt();
        $ttlDate = $lastFetchedAt?->modify('+' . self::CACHE_TTL_DAYS . ' days');

        // Check cache logic: If it hasn't been fetched fully yet (no servings or missing macros) OR if cache expired
        $firstServing = $food->getServings()->first() ?: null;
        $needsHydration = !$firstServing || $firstServing->getProteins() === null;
        $isExpired = $ttlDate === null || $now > $ttlDate;

        if ($needsHydration || $isExpired) {
            $this->refreshFoodDetails($food);
        }

        return $this->formatFoodArray($food);
    }

    private function upsertFromRemoteSearch(array $remoteData): ?Food
    {
        if (!isset($remoteData['id'], $remoteData['title'])) {
            return null;
        }

        $externalId = (string) $remoteData['id'];
        $food = $this->foodRepo->findOneBy(['externalId' => $externalId]);
        $isNew = false;

        if (!$food) {
            $food = new Food();
            $food->setExternalId($externalId);
            $food->setLastFetchedAt(new \DateTimeImmutable());
            $isNew = true;
        }

        // Clean titles html entities like Pl&#225;tano
        $title = trim(html_entity_decode((string) $remoteData['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $brand = isset($remoteData['brand_name']) && $remoteData['brand_name'] !== ''
            ? trim((string) $remoteData['brand_name'])
            : null;

        $isDirty = $isNew;
        if ($food->getName() !== $title) {
            $food->setName($title);
            $isDirty = true;
        }
        if ($food->getBrand() !== $brand) {
            $food->setBrand($brand);
            $isDirty = true;
        }

        // Parse portion & energy from remote search data
        $rawEnergy = isset($remoteData['energy']) && $remoteData['energy'] !== ''
            ? (float) $remoteData['energy']
            : null;
        $portionDesc = isset($remoteData['portion_description'])
            ? (string) $remoteData['portion_description']
            : '100g';
        $portion = $this->parsePortion($portionDesc, $rawEnergy);

        // If food has no servings yet, create initial serving with search calories & portion
        if ($food->getServings()->isEmpty() && $portion['calories'] !== null) {
            $serving = new Serving();
            $serving->setFood($food);
            $serving->setDescription($portion['description']);
            $serving->setAmount($portion['amount']);
            $serving->setUnit($portion['unit']);
            $serving->setCalories($portion['calories']);
            // proteins, carbs, fats remain null until getFoodDetails is called
            $food->addServing($serving);
            $this->em->persist($serving);
            $isDirty = true;
        }

        if ($isDirty) {
            $food->setUpdatedAt(new \DateTimeImmutable());
            $this->em->persist($food);
            $this->em->flush();

            // Ensure bestServingId points to the serving
            if ($food->getBestServingId() === null && !$food->getServings()->isEmpty()) {
                $food->setBestServingId($food->getServings()->first()->getId());
            }
        }

        return $food;
    }

    private function parsePortion(string $desc, ?float $energy): array
    {
        $desc = trim(html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $amount = 100.0;
        $unit = 'g';
        $calories = $energy !== null ? (float) $energy : null;

        // Pattern 1: Parenthesized grams or ml, e.g. "1 rebanada (32g)" or "1 lata (330ml)"
        if (preg_match('/\((\d+(?:[.,]\d+)?)\s*(g|ml)\)/i', $desc, $matches)) {
            $amount = (float) str_replace(',', '.', $matches[1]);
            $unit = strtolower($matches[2]);
        }
        // Pattern 2: Direct amount, e.g. "100g", "250 ml", "100 g"
        elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*(g|ml)$/i', $desc, $matches)) {
            $amount = (float) str_replace(',', '.', $matches[1]);
            $unit = strtolower($matches[2]);
        }
        // Pattern 3: Lone "g" or "1g" with energy per gram (e.g. 1.95 kcal/g)
        elseif (strtolower($desc) === 'g' || strtolower($desc) === '1g') {
            $amount = 100.0;
            $unit = 'g';
            if ($calories !== null && $calories < 20.0) {
                $calories = round($calories * 100.0, 1);
            }
            $desc = '100g';
        }

        if (empty($desc)) {
            $desc = $amount . $unit;
        }

        return [
            'description' => $desc,
            'amount' => $amount,
            'unit' => $unit,
            'calories' => $calories,
        ];
    }

    private function refreshFoodDetails(Food $food): void
    {
        $externalId = $food->getExternalId();
        if (!$externalId || str_starts_with($externalId, 'custom_')) {
            return;
        }
        
        // Fetch detailed macros using Info Endpoint
        $macros = $this->scraper->getInfo($externalId);
        
        if ($macros === null) {
            // Scraper failed or 404
            return;
        }

        // Preserve previous portion description, amount, and unit if existing
        $existingServing = $food->getServings()->first() ?: null;
        $prevDesc = $existingServing?->getDescription() ?? '100g';
        $prevAmount = $existingServing?->getAmount() ?? 100.0;
        $prevUnit = $existingServing?->getUnit() ?? 'g';

        if ($existingServing) {
            $serving = $existingServing;
        } else {
            $serving = new Serving();
            $serving->setFood($food);
            $serving->setDescription($prevDesc);
            $serving->setAmount($prevAmount);
            $serving->setUnit($prevUnit);
            $food->addServing($serving);
            $this->em->persist($serving);
        }

        // Set detailed macros
        $serving->setCalories((float) $macros['calories']);
        $serving->setProteins((float) $macros['proteins']);
        $serving->setCarbs((float) $macros['carbs']);
        $serving->setFats((float) $macros['fats']);

        $food->setLastFetchedAt(new \DateTimeImmutable());
        $food->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($serving->getId() !== null) {
            $food->setBestServingId($serving->getId());
            $this->em->flush();
        }
    }

    private function formatFoodArray(Food $food): array
    {
        $servings = [];
        $hasCompleteMacros = false;
        $hasCalories = false;

        $bestServing = null;
        if ($food->getBestServingId()) {
            foreach ($food->getServings() as $s) {
                if ($s->getId()?->toRfc4122() === $food->getBestServingId()->toRfc4122()) {
                    $bestServing = $s;
                    break;
                }
            }
        }
        if (!$bestServing && !$food->getServings()->isEmpty()) {
            $bestServing = $food->getServings()->first();
        }

        foreach ($food->getServings() as $serving) {
            $cal = $serving->getCalories();
            $p = $serving->getProteins();
            $c = $serving->getCarbs();
            $f = $serving->getFats();

            if ($cal !== null) {
                $hasCalories = true;
            }
            if ($p !== null && $c !== null && $f !== null) {
                $hasCompleteMacros = true;
            }

            $servings[] = [
                'id' => $serving->getId()?->toRfc4122(),
                'description' => $serving->getDescription(),
                'amount' => $serving->getAmount() ?: 100.0,
                'unit' => $serving->getUnit() ?: 'g',
                'calories' => $cal !== null ? (float) $cal : null,
                'proteins' => $p !== null ? (float) $p : null,
                'carbs' => $c !== null ? (float) $c : null,
                'fats' => $f !== null ? (float) $f : null,
            ];
        }

        $nutritionStatus = 'unavailable';
        if ($hasCompleteMacros) {
            $nutritionStatus = 'available';
        } elseif ($hasCalories) {
            $nutritionStatus = 'requires_detail';
        }

        $mainAmount = $bestServing ? ($bestServing->getAmount() ?: 100.0) : 100.0;
        $mainCal = $bestServing ? $bestServing->getCalories() : null;
        $mainP = $bestServing ? $bestServing->getProteins() : null;
        $mainC = $bestServing ? $bestServing->getCarbs() : null;
        $mainF = $bestServing ? $bestServing->getFats() : null;

        return [
            'id' => $food->getId()?->toRfc4122(),
            'name' => $food->getName(),
            'brand' => $food->getBrand(),
            'nutritionStatus' => $nutritionStatus,
            'hasNutritionInfo' => $hasCalories,
            'servingId' => $bestServing?->getId()?->toRfc4122(),
            'baseServingGrams' => $mainAmount,
            'calories' => $mainCal !== null ? (float) $mainCal : null,
            'proteins' => $mainP !== null ? (float) $mainP : null,
            'carbs' => $mainC !== null ? (float) $mainC : null,
            'fats' => $mainF !== null ? (float) $mainF : null,
            'servings' => $servings,
        ];
    }
}
