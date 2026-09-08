<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ProgressAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/v1/progress', name: 'api_progress_')]
class ProgressController extends AbstractController
{
    public function __construct(
        private ProgressAnalyticsService $analyticsService
    ) {}

    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', '4w');
        $data = $this->analyticsService->getSummary($user, $period);

        return $this->json($data);
    }

    #[Route('/body', name: 'body', methods: ['GET'])]
    public function body(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', '4w');
        $metric = $request->query->get('metric', 'weight_kg');

        $data = $this->analyticsService->getBodyProgress($user, $period, $metric);

        return $this->json($data);
    }

    #[Route('/training', name: 'training', methods: ['GET'])]
    public function training(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', '4w');
        $data = $this->analyticsService->getTrainingProgress($user, $period);

        return $this->json($data);
    }

    #[Route('/strength', name: 'strength', methods: ['GET'])]
    public function strength(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', '4w');
        $exerciseId = $request->query->get('exercise_id');

        $data = $this->analyticsService->getStrengthProgress($user, $period, $exerciseId);

        return $this->json($data);
    }

    #[Route('/nutrition', name: 'nutrition', methods: ['GET'])]
    public function nutrition(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $period = $request->query->get('period', '4w');
        $data = $this->analyticsService->getNutritionProgress($user, $period);

        return $this->json($data);
    }
}
