<?php

namespace App\Service;

use App\Entity\Food;
use App\Repository\FoodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class FoodService
{
    private const SEARCH_PAGE_SIZE = 20;

    public function __construct(
        private FoodRepository $foodRepo,
        private EntityManagerInterface $em
    ) {}

    public function search(string $query, int $page = 0): array
    {
        $query = trim($query);
        if ($query === '') return [];

        $page = max(0, $page);
        $offset = $page * self::SEARCH_PAGE_SIZE;
        $foods = $this->foodRepo->searchByNameOrBrand($query, self::SEARCH_PAGE_SIZE, $offset);

        return array_map(fn (Food $food) => $this->formatFoodArray($food), $foods);
    }

    public function getFoodDetails(string $id): ?array
    {
        try {
            $food = $this->foodRepo->find(Uuid::fromString($id));
        } catch (\Exception) {
            return null;
        }
        if (!$food) return null;

        return $this->formatFoodArray($food);
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
