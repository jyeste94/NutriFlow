<?php

namespace App\Controller;

use App\Service\FoodService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/v1/foods', name: 'api_foods_')]
class FoodController extends AbstractController
{
    public function __construct(private FoodService $foodService) {}

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
        try {
            $food = $this->foodService->getFoodDetails($id);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Invalid ID format or not found'], 400);
        }

        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        return $this->json($food);
    }
}
