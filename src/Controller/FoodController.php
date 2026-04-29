<?php

namespace App\Controller;

use App\Entity\Food;
use App\Entity\Serving;
use App\Service\FoodService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/v1/foods', name: 'api_foods_')]
class FoodController extends AbstractController
{
    public function __construct(
        private FoodService $foodService,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $page = $request->query->getInt('page', 0);

        if (empty($query)) {
            return $this->json(['error' => 'Query parameter "q" is required'], 400);
        }

        $results = $this->foodService->search($query, $page);

        return $this->json($results);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function getFood(string $id): JsonResponse
    {
        $food = $this->foodService->getFoodDetails($id);

        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        return $this->json($food);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return $this->json(['error' => 'Food name is required'], 400);
        }

        $food = new Food();
        $food->setName($data['name']);
        $food->setBrand($data['brand'] ?? null);
        $food->setExternalId('custom_' . uniqid());
        $food->setLastFetchedAt(new \DateTimeImmutable());
        $food->setCreatedAt(new \DateTimeImmutable());
        $food->setUpdatedAt(new \DateTimeImmutable());

        $serving = new Serving();
        $serving->setFood($food);
        $serving->setDescription($data['serving_description'] ?? '100g');
        $serving->setCalories($data['calories'] ?? 0);
        $serving->setProteins($data['proteins'] ?? 0);
        $serving->setCarbs($data['carbs'] ?? 0);
        $serving->setFats($data['fats'] ?? 0);

        if (isset($data['amount'])) $serving->setAmount($data['amount']);
        if (isset($data['unit'])) $serving->setUnit($data['unit']);

        $food->addServing($serving);
        $food->setBestServingId($serving->getId());

        $this->em->persist($food);
        $this->em->flush();

        return $this->json($this->foodService->getFoodDetails($food->getId()), 201);
    }
}
