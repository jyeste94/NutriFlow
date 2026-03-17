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

        // Check cache logic: If it hasn't been fetched fully yet (no servings) OR if cache expired
        if ($food->getServings()->isEmpty() || $ttlDate === null || $now > $ttlDate) {
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

        if ($isDirty) {
            $food->setUpdatedAt(new \DateTimeImmutable());
            $this->em->persist($food);
        }

        return $food;
    }

    private function refreshFoodDetails(Food $food): void
    {
        $externalId = $food->getExternalId();
        
        // Fetch detailed macros using Info Endpoint
        $macros = $this->scraper->getInfo($externalId);
        
        if ($macros === null) {
            // HTML parser failed or 404
            return;
        }

        // Wipe old servings
        foreach ($food->getServings() as $serving) {
            $this->em->remove($serving);
        }
        $food->getServings()->clear();
        $this->em->flush(); // To ensure deletion is performed before inserting new

        $serving = new Serving();
        $serving->setFood($food);
        
        // The search endpoint gives a portion description (e.g. "1 pieza (130g)") but the info 
        // endpoint does not return it directly inside the HTML cleanly. 
        // We'll set a generic '100g/unit' description, or ideally we'd pass it from search.
        // For now, we put "Standard Serving".
        $serving->setDescription("Standard Serving");
        
        $serving->setCalories($macros['calories']);
        $serving->setProteins($macros['proteins']);
        $serving->setCarbs($macros['carbs']);
        $serving->setFats($macros['fats']);
        
        $food->addServing($serving);
        $this->em->persist($serving);

        $this->em->flush();

        $food->setLastFetchedAt(new \DateTimeImmutable());
        $food->setUpdatedAt(new \DateTimeImmutable());
        if ($serving->getId() !== null) {
            $food->setBestServingId($serving->getId());
        }

        $this->em->flush();
    }

    private function formatFoodArray(Food $food): array
    {
        $servings = [];
        foreach ($food->getServings() as $serving) {
            $servings[] = [
                'id' => $serving->getId()->toRfc4122(),
                'description' => $serving->getDescription(),
                'calories' => $serving->getCalories(),
                'proteins' => $serving->getProteins(),
                'carbs' => $serving->getCarbs(),
                'fats' => $serving->getFats(),
            ];
        }

        return [
            'id' => $food->getId()->toRfc4122(),
            'name' => $food->getName(),
            'brand' => $food->getBrand(),
            'servings' => $servings,
        ];
    }
}
