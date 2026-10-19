<?php

namespace App\Controller;

use App\Entity\Food;
use App\Entity\MealEntry;
use App\Entity\Serving;
use App\Entity\UserFavoriteFood;
use App\Service\FoodService;
use App\Service\OpenFoodFactsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
    /// <summary>Searches shared foods and foods owned by the current account.</summary>
    /// <param name="request">Search query and page.</param>
    /// <returns>Accessible food search results.</returns>
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $page = $request->query->getInt('page', 0);

        if (empty($query)) {
            return $this->json(['error' => 'Query parameter "q" is required'], 400);
        }

        $results = $this->foodService->search($query, $page, $this->getUser());

        return $this->json($results);
    }

    #[Route('/barcode/{barcode}', name: 'barcode', methods: ['GET'])]
    public function barcode(string $barcode, OpenFoodFactsService $provider): JsonResponse
    {
        try {
            $product = $provider->lookup($barcode);
        } catch (HttpExceptionInterface $error) {
            return $this->json(['error' => $error->getMessage()], $error->getStatusCode());
        }
        return $product === null
            ? $this->json(['error' => 'No encontramos este código en Open Food Facts.', 'status' => 'not_found'], 404)
            : $this->json($product);
    }

    #[Route('/barcode/{barcode}', name: 'import_barcode', methods: ['POST'])]
    public function importBarcode(string $barcode, OpenFoodFactsService $provider): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        if (!preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $barcode)) {
            return $this->json(['error' => 'Código de barras no válido.'], 400);
        }
        $externalId = 'custom_off_' . $user->getId()->toRfc4122() . '_' . $barcode;
        $food = $this->em->getRepository(Food::class)->findOneBy(['externalId' => $externalId, 'user' => $user]);
        if ($food) {
            return $this->json($this->foodService->getFoodDetails($food->getId()->toRfc4122(), $user));
        }
        try {
            $product = $provider->lookup($barcode);
        } catch (HttpExceptionInterface $error) {
            return $this->json(['error' => $error->getMessage()], $error->getStatusCode());
        }
        if ($product === null) {
            return $this->json(['error' => 'No encontramos este código en Open Food Facts.', 'status' => 'not_found'], 404);
        }
        if (!$product['canImport']) {
            return $this->json(['error' => 'El producto no tiene todos los nutrientes o su unidad no está confirmada. No se ha importado.'], 422);
        }

        $food = (new Food())
            ->setUser($user)
            ->setExternalId($externalId)
            ->setName($product['name'])
            ->setBrand($product['brand'])
            ->setLastFetchedAt(new \DateTimeImmutable());
        $serving = (new Serving())
            ->setFood($food)
            ->setDescription('100 ' . $product['unit'])
            ->setAmount(100)
            ->setUnit($product['unit'])
            ->setCalories($product['calories'])
            ->setProteins($product['proteins'])
            ->setCarbs($product['carbs'])
            ->setFats($product['fats']);
        $food->addServing($serving);
        $this->em->persist($food);
        $this->em->flush();
        $food->setBestServingId($serving->getId());
        $this->em->flush();
        return $this->json($this->foodService->getFoodDetails($food->getId()->toRfc4122(), $user), 201);
    }

    #[Route('/recent', name: 'recent', methods: ['GET'])]
    /// <summary>Lists recently used foods still accessible to the current account.</summary>
    /// <param name="request">Result limit.</param>
    /// <returns>Accessible recent foods.</returns>
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
            if (!$food || !$food->isAccessibleBy($user)) continue;
            $calories = $serving->getCalories();
            $hasInfo = $calories !== null;
            $foods[] = [
                'id' => $food->getId()?->toRfc4122(),
                'name' => $food->getName(),
                'brand' => $food->getBrand(),
                'servingId' => $serving->getId()?->toRfc4122(),
                'baseServingGrams' => $serving->getAmount() ?: 100,
                'unit' => $serving->getUnit(),
                'calories' => $hasInfo ? (float) $calories : null,
                'proteins' => $serving->getProteins(),
                'carbs' => $serving->getCarbs(),
                'fats' => $serving->getFats(),
                'hasNutritionInfo' => $hasInfo,
            ];
        }

        return $this->json($foods);
    }

    #[Route('/frequent', name: 'frequent', methods: ['GET'])]
    /// <summary>Lists frequently used foods still accessible to the current account.</summary>
    /// <param name="request">Result limit.</param>
    /// <returns>Accessible frequent foods.</returns>
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
            if (!$food || !$food->isAccessibleBy($user)) continue;
            $calories = $serving->getCalories();
            $hasInfo = $calories !== null;
            $foods[] = [
                'id' => $food->getId()?->toRfc4122(),
                'name' => $food->getName(),
                'brand' => $food->getBrand(),
                'servingId' => $serving->getId()?->toRfc4122(),
                'baseServingGrams' => $serving->getAmount() ?: 100,
                'unit' => $serving->getUnit(),
                'calories' => $hasInfo ? (float) $calories : null,
                'proteins' => $serving->getProteins(),
                'carbs' => $serving->getCarbs(),
                'fats' => $serving->getFats(),
                'hasNutritionInfo' => $hasInfo,
            ];
        }

        return $this->json($foods);
    }

    #[Route('/favorites', name: 'favorites', methods: ['GET'])]
    /// <summary>Lists favorites without exposing legacy references to another account's food.</summary>
    /// <returns>Accessible favorite foods.</returns>
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
            if (!$food || !$food->isAccessibleBy($user)) continue;
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
                'unit' => $bestServing?->getUnit(),
                'calories' => $bestServing?->getCalories(),
                'proteins' => $bestServing?->getProteins(),
                'carbs' => $bestServing?->getCarbs(),
                'fats' => $bestServing?->getFats(),
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
                'unit' => $serving?->getUnit(),
                'calories' => $serving?->getCalories(),
                'proteins' => $serving?->getProteins(),
                'carbs' => $serving?->getCarbs(),
                'fats' => $serving?->getFats(),
            ];
        }

        return $this->json($result);
    }

    #[Route('/custom', name: 'create_custom', methods: ['POST'])]
    /// <summary>Creates a personal food after validating its name, portion and nutrients.</summary>
    /// <param name="request">Food creation payload.</param>
    /// <returns>The created food or a validation error.</returns>
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
        if ($error = $this->validateNutrition($data, true)) {
            return $error;
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
        $serving->setDescription($data['serving_description'] ?? ($amountGrams . ($data['unit'] ?? 'g')));
        $serving->setCalories((float) $data['calories']);
        $serving->setProteins(isset($data['proteins']) ? (float) $data['proteins'] : null);
        $serving->setCarbs(isset($data['carbs']) ? (float) $data['carbs'] : null);
        $serving->setFats(isset($data['fats']) ? (float) $data['fats'] : null);
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
            'unit' => $serving->getUnit(),
            'calories' => $serving->getCalories(),
            'proteins' => $serving->getProteins(),
            'carbs' => $serving->getCarbs(),
            'fats' => $serving->getFats(),
        ], 201);
    }

    #[Route('/custom/{id}', name: 'update_custom', methods: ['PUT'])]
    /// <summary>Updates an owned personal food after validating changed nutrition and portion values.</summary>
    /// <param name="id">Food identifier.</param>
    /// <param name="request">Food changes.</param>
    /// <returns>The updated food or a validation or access error.</returns>
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

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        if ($error = $this->validateNutrition($data)) {
            return $error;
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
            if (isset($data['unit'])) $serving->setUnit($data['unit']);
            if (isset($data['amountGrams']) || isset($data['amount'])) {
                $amount = (float) ($data['amountGrams'] ?? $data['amount']);
                $serving->setAmount($amount);
                $serving->setDescription($amount . ($serving->getUnit() ?? 'g'));
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
            'unit' => $serving?->getUnit(),
            'calories' => $serving?->getCalories(),
            'proteins' => $serving?->getProteins(),
            'carbs' => $serving?->getCarbs(),
            'fats' => $serving?->getFats(),
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
    /// <summary>Favorites an accessible food for the current account.</summary>
    /// <param name="id">Food identifier.</param>
    /// <returns>The favorite state or an access error.</returns>
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

        if (!$food->isAccessibleBy($user)) {
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
    /// <summary>Returns food details without exposing another account's personal foods.</summary>
    /// <param name="id">Food identifier.</param>
    /// <returns>Accessible food details or a not-found error.</returns>
    public function getFood(string $id): JsonResponse
    {
        $food = $this->foodService->getFoodDetails($id, $this->getUser());

        if (!$food) {
            return $this->json(['error' => 'Food not found'], 404);
        }

        return $this->json($food);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    /// <summary>Creates a personal food and returns its details to its owner.</summary>
    /// <param name="request">Food creation payload.</param>
    /// <returns>The created personal food.</returns>
    public function create(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
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
        if ($error = $this->validateNutrition($data, true)) {
            return $error;
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
        $amount = (float) ($data['amountGrams'] ?? $data['amount'] ?? 100);
        $unit = $data['unit'] ?? 'g';
        $serving->setDescription($data['serving_description'] ?? ($amount . $unit));
        $serving->setCalories((float) $data['calories']);
        $serving->setProteins(isset($data['proteins']) ? (float) $data['proteins'] : null);
        $serving->setCarbs(isset($data['carbs']) ? (float) $data['carbs'] : null);
        $serving->setFats(isset($data['fats']) ? (float) $data['fats'] : null);
        $serving->setAmount($amount);
        $serving->setUnit($unit);

        $food->addServing($serving);

        $this->em->persist($food);
        $this->em->flush();
        $food->setBestServingId($serving->getId());
        $this->em->flush();

        return $this->json($this->foodService->getFoodDetails($food->getId()->toRfc4122(), $this->getUser()), 201);
    }

    /// <summary>Rejects blank names, invalid nutrients and non-positive portions before persistence.</summary>
    /// <param name="data">Food fields to validate.</param>
    /// <returns>A validation response, or null when all supplied values are valid.</returns>
    private function validateNutrition(array $data, bool $creating = false): ?JsonResponse
    {
        if ($creating && !array_key_exists('calories', $data)) {
            return $this->json(['error' => 'Las calorías son obligatorias; no podemos inventar valores desconocidos.'], 400);
        }
        foreach (['name' => 255, 'brand' => 255, 'unit' => 50, 'serving_description' => 255] as $field => $maximum) {
            if (!isset($data[$field])) continue;
            if (!is_string($data[$field]) || mb_strlen($data[$field]) > $maximum) {
                return $this->json(['error' => "$field must be text up to $maximum characters"], 400);
            }
        }
        if (array_key_exists('name', $data) && (!is_string($data['name']) || trim($data['name']) === '')) {
            return $this->json(['error' => 'Food name is required'], 400);
        }
        foreach (['amountGrams', 'amount', 'calories', 'proteins', 'carbs', 'fats'] as $field) {
            if (!array_key_exists($field, $data)) continue;
            $value = filter_var($data[$field], FILTER_VALIDATE_FLOAT);
            $isAmount = $field === 'amountGrams' || $field === 'amount';
            if ($value === false || !is_finite($value) || $value < 0 || ($isAmount && $value == 0)) {
                return $this->json(['error' => "$field must be " . ($isAmount ? 'positive' : 'non-negative') . ' and finite'], 400);
            }
        }
        return null;
    }
}
