<?php

namespace App\Controller;

use App\Entity\Food;
use App\Entity\MealEntry;
use App\Entity\Serving;
use App\Entity\UserFavoriteFood;
use App\Service\FoodService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

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

    #[Route('/recent', name: 'recent', methods: ['GET'])]
    public function getRecentFoods(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $limit = max(1, min(50, $request->query->getInt('limit', 20)));

        $qb = $this->em->createQueryBuilder();
        $results = $qb->select('s', 'MAX(e.createdAt) AS HIDDEN maxCreated')
            ->from(Serving::class, 's')
            ->join('s.food', 'f')
            ->join(MealEntry::class, 'e', 'WITH', 'e.serving = s')
            ->join('e.diary', 'd')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->groupBy('s.id')
            ->orderBy('maxCreated', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $foods = [];
        /** @var Serving $serving */
        foreach ($results as $serving) {
            $food = $serving->getFood();
            if (!$food) continue;
            $calories = $serving->getCalories();
            $hasInfo = $calories !== null;
            $foods[] = [
                'id' => $food->getId()?->toRfc4122(),
                'name' => $food->getName(),
                'brand' => $food->getBrand(),
                'servingId' => $serving->getId()?->toRfc4122(),
                'baseServingGrams' => $serving->getAmount() ?: 100,
                'calories' => $hasInfo ? (float) $calories : null,
                'proteins' => $serving->getProteins() !== null ? (float) $serving->getProteins() : 0.0,
                'carbs' => $serving->getCarbs() !== null ? (float) $serving->getCarbs() : 0.0,
                'fats' => $serving->getFats() !== null ? (float) $serving->getFats() : 0.0,
                'hasNutritionInfo' => $hasInfo,
            ];
        }

        return $this->json($foods);
    }

    #[Route('/frequent', name: 'frequent', methods: ['GET'])]
    public function getFrequentFoods(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $limit = max(1, min(50, $request->query->getInt('limit', 15)));

        $qb = $this->em->createQueryBuilder();
        $results = $qb->select('s', 'COUNT(e.id) AS HIDDEN entryCount')
            ->from(Serving::class, 's')
            ->join('s.food', 'f')
            ->join(MealEntry::class, 'e', 'WITH', 'e.serving = s')
            ->join('e.diary', 'd')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->groupBy('s.id')
            ->orderBy('entryCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $foods = [];
        /** @var Serving $serving */
        foreach ($results as $serving) {
            $food = $serving->getFood();
            if (!$food) continue;
            $calories = $serving->getCalories();
            $hasInfo = $calories !== null;
            $foods[] = [
                'id' => $food->getId()?->toRfc4122(),
                'name' => $food->getName(),
                'brand' => $food->getBrand(),
                'servingId' => $serving->getId()?->toRfc4122(),
                'baseServingGrams' => $serving->getAmount() ?: 100,
                'calories' => $hasInfo ? (float) $calories : null,
                'proteins' => $serving->getProteins() !== null ? (float) $serving->getProteins() : 0.0,
                'carbs' => $serving->getCarbs() !== null ? (float) $serving->getCarbs() : 0.0,
                'fats' => $serving->getFats() !== null ? (float) $serving->getFats() : 0.0,
                'hasNutritionInfo' => $hasInfo,
            ];
        }

        return $this->json($foods);
    }

    #[Route('/favorites', name: 'favorites', methods: ['GET'])]
    public function getFavorites(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $favs = $this->em->getRepository(UserFavoriteFood::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);
        $result = [];
        /** @var UserFavoriteFood $fav */
        foreach ($favs as $fav) {
            $food = $fav->getFood();
            if (!$food) continue;
            $bestServing = null;
            if ($food->getBestServingId()) {
                $bestServing = $this->em->getRepository(Serving::class)->find($food->getBestServingId());
            }
            if (!$bestServing && !$food->getServings()->isEmpty()) {
                $bestServing = $food->getServings()->first();
            }

            $hasInfo = $bestServing !== null;
            $result[] = [
                'id' => $food->getId()?->toRfc4122(),
                'name' => $food->getName(),
                'brand' => $food->getBrand(),
                'servingId' => $bestServing?->getId()?->toRfc4122(),
                'baseServingGrams' => $bestServing?->getAmount() ?: 100,
                'calories' => $hasInfo ? ($bestServing->getCalories() !== null ? (float) $bestServing->getCalories() : 0.0) : null,
                'proteins' => $hasInfo ? ($bestServing->getProteins() !== null ? (float) $bestServing->getProteins() : 0.0) : null,
                'carbs' => $hasInfo ? ($bestServing->getCarbs() !== null ? (float) $bestServing->getCarbs() : 0.0) : null,
                'fats' => $hasInfo ? ($bestServing->getFats() !== null ? (float) $bestServing->getFats() : 0.0) : null,
                'hasNutritionInfo' => $hasInfo,
                'isFavorite' => true,
            ];
        }

        return $this->json($result);
    }

    #[Route('/custom', name: 'list_custom', methods: ['GET'])]
    public function listCustom(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $foods = $this->em->getRepository(Food::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);
        $result = [];
        /** @var Food $food */
        foreach ($foods as $food) {
            $serving = $food->getServings()->first() ?: null;
            $result[] = [
                'id' => $food->getId()?->toRfc4122(),
                'name' => $food->getName(),
                'brand' => $food->getBrand(),
                'servingId' => $serving?->getId()?->toRfc4122(),
                'baseServingGrams' => $serving?->getAmount() ?: 100,
                'calories' => $serving?->getCalories() ?: 0,
                'proteins' => $serving?->getProteins() ?: 0,
                'carbs' => $serving?->getCarbs() ?: 0,
                'fats' => $serving?->getFats() ?: 0,
            ];
        }

        return $this->json($result);
    }

    #[Route('/custom', name: 'create_custom', methods: ['POST'])]
    public function createCustom(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data) || empty($data['name'])) {
            return $this->json(['error' => 'Food name is required'], 400);
        }

        $food = new Food();
        $food->setUser($user);
        $food->setName(trim((string) $data['name']));
        $food->setBrand(isset($data['brand']) && trim((string) $data['brand']) !== '' ? trim((string) $data['brand']) : null);
        $food->setExternalId('custom_' . uniqid('', true));
        $food->setLastFetchedAt(new \DateTimeImmutable());
        $food->setCreatedAt(new \DateTimeImmutable());
        $food->setUpdatedAt(new \DateTimeImmutable());

        $amountGrams = (float) ($data['amountGrams'] ?? $data['amount'] ?? 100);
        $serving = new Serving();
        $serving->setFood($food);
        $serving->setDescription($data['serving_description'] ?? ($amountGrams . 'g'));
        $serving->setCalories((float) ($data['calories'] ?? 0));
        $serving->setProteins((float) ($data['proteins'] ?? 0));
        $serving->setCarbs((float) ($data['carbs'] ?? 0));
        $serving->setFats((float) ($data['fats'] ?? 0));
        $serving->setAmount($amountGrams);
        $serving->setUnit($data['unit'] ?? 'g');

        $food->addServing($serving);
        $this->em->persist($food);
        $this->em->persist($serving);
        $this->em->flush();

        $food->setBestServingId($serving->getId());
        $this->em->flush();

        return $this->json([
            'id' => $food->getId()?->toRfc4122(),
            'name' => $food->getName(),
            'brand' => $food->getBrand(),
            'servingId' => $serving->getId()?->toRfc4122(),
            'baseServingGrams' => $serving->getAmount() ?: 100,
            'calories' => $serving->getCalories(),
            'proteins' => $serving->getProteins(),
            'carbs' => $serving->getCarbs(),
            'fats' => $serving->getFats(),
        ], 201);
    }

    #[Route('/custom/{id}', name: 'update_custom', methods: ['PUT'])]
    public function updateCustom(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $food = $this->em->getRepository(Food::class)->find($id);
        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        if ($food->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $food->setName(trim((string) $data['name']));
        }
        if (array_key_exists('brand', $data)) {
            $food->setBrand(isset($data['brand']) && trim((string) $data['brand']) !== '' ? trim((string) $data['brand']) : null);
        }

        $serving = $food->getServings()->first();
        if ($serving) {
            if (isset($data['calories'])) $serving->setCalories((float) $data['calories']);
            if (isset($data['proteins'])) $serving->setProteins((float) $data['proteins']);
            if (isset($data['carbs'])) $serving->setCarbs((float) $data['carbs']);
            if (isset($data['fats'])) $serving->setFats((float) $data['fats']);
            if (isset($data['amountGrams']) || isset($data['amount'])) {
                $amount = (float) ($data['amountGrams'] ?? $data['amount']);
                $serving->setAmount($amount);
                $serving->setDescription($amount . 'g');
            }
        }

        $food->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'id' => $food->getId()?->toRfc4122(),
            'name' => $food->getName(),
            'brand' => $food->getBrand(),
            'servingId' => $serving?->getId()?->toRfc4122(),
            'baseServingGrams' => $serving?->getAmount() ?: 100,
            'calories' => $serving?->getCalories() ?: 0,
            'proteins' => $serving?->getProteins() ?: 0,
            'carbs' => $serving?->getCarbs() ?: 0,
            'fats' => $serving?->getFats() ?: 0,
        ]);
    }

    #[Route('/custom/{id}', name: 'delete_custom', methods: ['DELETE'])]
    public function deleteCustom(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $food = $this->em->getRepository(Food::class)->find($id);
        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        if ($food->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->em->remove($food);
        $this->em->flush();

        return $this->json(['message' => 'Custom food deleted successfully']);
    }

    #[Route('/{id}/favorite', name: 'add_favorite', methods: ['POST'])]
    public function addFavorite(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $food = $this->em->getRepository(Food::class)->find($id);
        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        $fav = $this->em->getRepository(UserFavoriteFood::class)->findOneBy(['user' => $user, 'food' => $food]);
        if (!$fav) {
            $fav = new UserFavoriteFood();
            $fav->setUser($user);
            $fav->setFood($food);
            $this->em->persist($fav);
            $this->em->flush();
        }

        return $this->json(['isFavorite' => true, 'foodId' => $food->getId()?->toRfc4122()], 201);
    }

    #[Route('/{id}/favorite', name: 'remove_favorite', methods: ['DELETE'])]
    public function removeFavorite(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $food = $this->em->getRepository(Food::class)->find($id);
        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        $fav = $this->em->getRepository(UserFavoriteFood::class)->findOneBy(['user' => $user, 'food' => $food]);
        if ($fav) {
            $this->em->remove($fav);
            $this->em->flush();
        }

        return $this->json(['isFavorite' => false]);
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
        $food->setUser($this->getUser());
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
