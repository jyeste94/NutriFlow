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
        // Si el ID es inválido o el servicio falla, la excepción subirá al Kernel 
        // y nuestro ExceptionListener la guardará en la tabla error_logs.
        $food = $this->foodService->getFoodDetails($id);

        if (!$food) {
            // Lanzamos una excepción de Symfony para que el listener pueda capturarla si queremos,
            // o simplemente devolvemos el JSON. Para que se guarde en la tabla 'errors', 
            // lanzamos una excepción aquí también.
            throw $this->createNotFoundException('Food not found with ID: ' . $id);
        }

        return $this->json($food);
    }
}
