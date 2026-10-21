<?php

namespace App\Controller;

use App\Entity\SavedMeal;
use App\Entity\SavedMealItem;
use App\Entity\Serving;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/saved-meals', name: 'api_saved_meals_')]
class SavedMealController extends AbstractController
{
    private const MEAL_TYPES = ['breakfast', 'almuerzo', 'lunch', 'merienda', 'dinner', 'snack'];

    public function __construct(private EntityManagerInterface $em) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $meals = $this->em->getRepository(SavedMeal::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);
        return $this->json(array_map(fn (SavedMeal $meal) => $this->formatSavedMeal($meal), $meals));
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function getSavedMeal(string $id): JsonResponse
    {
        if (!$this->getUser()) return $this->json(['error' => 'Unauthorized'], 401);
        $meal = Uuid::isValid($id) ? $this->em->getRepository(SavedMeal::class)->find($id) : null;
        if (!$meal) return $this->json(['error' => 'Saved meal not found'], 404);
        if ($meal->getUser() !== $this->getUser()) return $this->json(['error' => 'Forbidden'], 403);
        return $this->json($this->formatSavedMeal($meal));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Unauthorized'], 401);
        $data = $this->parsePayload($request);
        if ($data instanceof JsonResponse) return $data;
        $clientId = $data['client_id'] ?? null;
        if ($clientId !== null && (!is_string($clientId) || !Uuid::isValid($clientId))) {
            return $this->json(['error' => 'client_id must be a UUID'], 400);
        }
        return $this->em->wrapInTransaction(function () use ($user, $data, $clientId): JsonResponse {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            if ($clientId !== null) {
                $existing = $this->em->getRepository(SavedMeal::class)->find($clientId);
                if ($existing) {
                    if ($existing->getUser() !== $user) return $this->json(['error' => 'client_id is already in use'], 409);
                    return $this->json($this->formatSavedMeal($existing));
                }
            }
            $meal = new SavedMeal($clientId !== null ? Uuid::fromString($clientId) : null);
            $meal->setUser($user);
            $error = $this->applyPayload($meal, $data, $user, true);
            if ($error) return $error;
            $this->em->persist($meal);
            $this->em->flush();
            return $this->json($this->formatSavedMeal($meal), 201);
        });
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Unauthorized'], 401);
        $meal = Uuid::isValid($id) ? $this->em->getRepository(SavedMeal::class)->find($id) : null;
        if (!$meal) return $this->json(['error' => 'Saved meal not found'], 404);
        if ($meal->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);
        $data = $this->parsePayload($request);
        if ($data instanceof JsonResponse) return $data;
        $error = $this->applyPayload($meal, $data, $user, false);
        if ($error) return $error;
        $meal->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
        return $this->json($this->formatSavedMeal($meal));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        if (!$this->getUser()) return $this->json(['error' => 'Unauthorized'], 401);
        $meal = Uuid::isValid($id) ? $this->em->getRepository(SavedMeal::class)->find($id) : null;
        if (!$meal) return new JsonResponse(null, 204);
        if ($meal->getUser() !== $this->getUser()) return $this->json(['error' => 'Forbidden'], 403);
        $this->em->remove($meal);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function parsePayload(Request $request): array|JsonResponse
    {
        try { $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $this->json(['error' => 'Invalid JSON body'], 400); }
        return is_array($data) ? $data : $this->json(['error' => 'Invalid JSON body'], 400);
    }

    /** Validate the entire replacement before changing a managed entity. */
    private function applyPayload(SavedMeal $meal, array $data, User $user, bool $creating): ?JsonResponse
    {
        $name = $data['name'] ?? ($creating ? '' : $meal->getName());
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            return $this->json(['error' => 'A meal name of at most 255 characters is required'], 400);
        }
        $mealType = $data['preferredMealType'] ?? $meal->getPreferredMealType();
        if ($mealType !== null && (!is_string($mealType) || !in_array(strtolower($mealType), self::MEAL_TYPES, true))) {
            return $this->json(['error' => 'Invalid preferredMealType'], 400);
        }
        $servings = filter_var($data['servings'] ?? $meal->getServings(), FILTER_VALIDATE_INT);
        if ($servings === false || $servings < 1 || $servings > 100) return $this->json(['error' => 'servings must be an integer from 1 to 100'], 400);
        $instructions = $data['instructions'] ?? $meal->getInstructions();
        if (!is_string($instructions) || mb_strlen($instructions) > 20000) return $this->json(['error' => 'Invalid instructions'], 400);
        $newItems = null;
        if ($creating || array_key_exists('items', $data)) {
            $items = $data['items'] ?? null;
            if (!is_array($items) || count($items) === 0 || count($items) > 100) return $this->json(['error' => 'Provide between 1 and 100 ingredients'], 400);
            $newItems = [];
            foreach ($items as $position => $itemData) {
                if (!is_array($itemData) || !is_string($itemData['name'] ?? null) || trim($itemData['name']) === '' || mb_strlen($itemData['name']) > 255) {
                    return $this->json(['error' => 'Each ingredient needs a valid name'], 400);
                }
                $servingId = $itemData['servingId'] ?? null;
                $unit = $itemData['unit'] ?? null;
                if (!in_array($unit, ['g', 'ml'], true)) {
                    return $this->json(['error' => 'Ingredient unit must be g or ml'], 400);
                }
                $values = [];
                foreach (['amount', 'calories', 'proteins', 'carbs', 'fats'] as $field) {
                    if (!isset($itemData[$field]) && ($field === 'amount' || $servingId === null || $servingId === '')) {
                        return $this->json(['error' => 'Ingredient must include ' . $field], 400);
                    }
                    $value = filter_var($itemData[$field] ?? 0, FILTER_VALIDATE_FLOAT);
                    if ($value === false || !is_finite($value) || $value < 0 || $value > 100000 || ($field === 'amount' && ($value <= 0 || $value > 10000))) {
                        return $this->json(['error' => 'Invalid ingredient ' . $field], 400);
                    }
                    $values[$field] = (float) $value;
                }
                $serving = null;
                if ($servingId !== null && $servingId !== '') {
                    if (!is_string($servingId) || !Uuid::isValid($servingId)) return $this->json(['error' => 'Invalid servingId'], 400);
                    $serving = $this->em->getRepository(Serving::class)->find($servingId);
                    if (!$serving || !$serving->getFood()?->isAccessibleBy($user)) return $this->json(['error' => 'Serving not found'], 404);
                    if ($serving->getAmount() <= 0 || SavedMealItem::normalizeServingUnit($serving->getUnit()) !== $unit) {
                        return $this->json(['error' => 'Ingredient and serving must use the same metric unit'], 400);
                    }
                    if (in_array(null, [$serving->getCalories(), $serving->getProteins(), $serving->getCarbs(), $serving->getFats()], true)) {
                        return $this->json(['error' => 'Serving nutrition is incomplete'], 400);
                    }
                    $ratio = $values['amount'] / $serving->getAmount();
                    $values['calories'] = $serving->getCalories() * $ratio;
                    $values['proteins'] = $serving->getProteins() * $ratio;
                    $values['carbs'] = $serving->getCarbs() * $ratio;
                    $values['fats'] = $serving->getFats() * $ratio;
                }
                $item = new SavedMealItem();
                $item->setName(trim($itemData['name']));
                $item->setBrand(isset($itemData['brand']) ? (string) $itemData['brand'] : null);
                $item->setAmount($values['amount']);
                $item->setUnit($unit);
                $item->setCalories($values['calories']);
                $item->setProteins($values['proteins']);
                $item->setCarbs($values['carbs']);
                $item->setFats($values['fats']);
                $item->setPosition(count($newItems));
                if ($serving) $item->setServing($serving);
                $newItems[] = $item;
            }
        }
        $meal->setName(trim($name));
        $meal->setPreferredMealType($mealType !== null ? strtolower($mealType) : null);
        $meal->setServings($servings);
        $meal->setInstructions($instructions);
        if ($newItems !== null) {
            foreach ($meal->getItems()->toArray() as $oldItem) {
                $meal->removeItem($oldItem);
                $this->em->remove($oldItem);
            }
            foreach ($newItems as $item) $meal->addItem($item);
        }
        return null;
    }

    private function formatSavedMeal(SavedMeal $meal): array
    {
        $items = [];
        foreach ($meal->getItems() as $item) {
            $items[] = [
                'id' => $item->getId()?->toRfc4122(), 'name' => $item->getName(), 'brand' => $item->getBrand(),
                'amount' => $item->getAmount(), 'unit' => $item->getUnit(), 'calories' => $item->getCalories(), 'proteins' => $item->getProteins(),
                'carbs' => $item->getCarbs(), 'fats' => $item->getFats(), 'servingId' => $item->hasCompatibleServing() ? $item->getServing()->getId()?->toRfc4122() : null,
                'baseServingAmount' => $item->hasCompatibleServing() ? $item->getServing()->getAmount() : null,
            ];
        }
        return [
            'id' => $meal->getId()?->toRfc4122(), 'name' => $meal->getName(), 'preferredMealType' => $meal->getPreferredMealType(),
            'servings' => $meal->getServings(), 'instructions' => $meal->getInstructions(),
            'totalCalories' => $meal->getTotalCalories(), 'totalProteins' => $meal->getTotalProteins(),
            'totalCarbs' => $meal->getTotalCarbs(), 'totalFats' => $meal->getTotalFats(),
            'createdAt' => $meal->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $meal->getUpdatedAt()?->format(\DateTimeInterface::ATOM), 'items' => $items,
        ];
    }
}
