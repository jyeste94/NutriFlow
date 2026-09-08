<?php

namespace App\Controller;

use App\Entity\SavedMeal;
use App\Entity\SavedMealItem;
use App\Entity\Serving;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/saved-meals', name: 'api_saved_meals_')]
class SavedMealController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $meals = $this->em->getRepository(SavedMeal::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->json(array_map(fn (SavedMeal $meal) => $this->formatSavedMeal($meal), $meals));
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function getSavedMeal(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $meal = $this->em->getRepository(SavedMeal::class)->find($id);
        if (!$meal) {
            return $this->json(['error' => 'Saved meal not found'], 404);
        }

        if ($meal->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return $this->json($this->formatSavedMeal($meal));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
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

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => 'Meal name is required'], 400);
        }

        $itemsData = $data['items'] ?? [];
        if (!is_array($itemsData) || count($itemsData) === 0) {
            return $this->json(['error' => 'At least one item is required in a saved meal combo'], 400);
        }

        $savedMeal = new SavedMeal();
        $savedMeal->setUser($user);
        $savedMeal->setName($name);
        if (!empty($data['preferredMealType'])) {
            $savedMeal->setPreferredMealType(strtolower((string) $data['preferredMealType']));
        }

        $position = 0;
        foreach ($itemsData as $itemData) {
            $itemName = trim((string) ($itemData['name'] ?? ''));
            if ($itemName === '') {
                continue;
            }

            $item = new SavedMealItem();
            $item->setName($itemName);
            $item->setBrand(isset($itemData['brand']) ? (string) $itemData['brand'] : null);
            $item->setAmountGrams((float) ($itemData['amountGrams'] ?? 100));
            $item->setCalories((float) ($itemData['calories'] ?? 0));
            $item->setProteins((float) ($itemData['proteins'] ?? 0));
            $item->setCarbs((float) ($itemData['carbs'] ?? 0));
            $item->setFats((float) ($itemData['fats'] ?? 0));
            $item->setPosition($position++);

            $servingId = (string) ($itemData['servingId'] ?? $itemData['serving_id'] ?? '');
            if ($servingId !== '' && Uuid::isValid($servingId)) {
                $serving = $this->em->getRepository(Serving::class)->find($servingId);
                if ($serving) {
                    $item->setServing($serving);
                }
            }

            $savedMeal->addItem($item);
        }

        $this->em->persist($savedMeal);
        $this->em->flush();

        return $this->json($this->formatSavedMeal($savedMeal), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $meal = $this->em->getRepository(SavedMeal::class)->find($id);
        if (!$meal) {
            return $this->json(['error' => 'Saved meal not found'], 404);
        }

        if ($meal->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name !== '') {
                $meal->setName($name);
            }
        }

        if (array_key_exists('preferredMealType', $data)) {
            $meal->setPreferredMealType($data['preferredMealType'] ? strtolower((string) $data['preferredMealType']) : null);
        }

        if (isset($data['items']) && is_array($data['items'])) {
            // Remove existing items
            foreach ($meal->getItems() as $existingItem) {
                $this->em->remove($existingItem);
            }
            $meal->getItems()->clear();

            $position = 0;
            foreach ($data['items'] as $itemData) {
                $itemName = trim((string) ($itemData['name'] ?? ''));
                if ($itemName === '') {
                    continue;
                }

                $item = new SavedMealItem();
                $item->setName($itemName);
                $item->setBrand(isset($itemData['brand']) ? (string) $itemData['brand'] : null);
                $item->setAmountGrams((float) ($itemData['amountGrams'] ?? 100));
                $item->setCalories((float) ($itemData['calories'] ?? 0));
                $item->setProteins((float) ($itemData['proteins'] ?? 0));
                $item->setCarbs((float) ($itemData['carbs'] ?? 0));
                $item->setFats((float) ($itemData['fats'] ?? 0));
                $item->setPosition($position++);

                $servingId = (string) ($itemData['servingId'] ?? $itemData['serving_id'] ?? '');
                if ($servingId !== '' && Uuid::isValid($servingId)) {
                    $serving = $this->em->getRepository(Serving::class)->find($servingId);
                    if ($serving) {
                        $item->setServing($serving);
                    }
                }

                $meal->addItem($item);
            }
        }

        $meal->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json($this->formatSavedMeal($meal));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $meal = $this->em->getRepository(SavedMeal::class)->find($id);
        if (!$meal) {
            return $this->json(['error' => 'Saved meal not found'], 404);
        }

        if ($meal->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->em->remove($meal);
        $this->em->flush();

        return $this->json(['message' => 'Saved meal deleted successfully']);
    }

    private function formatSavedMeal(SavedMeal $meal): array
    {
        $items = [];
        foreach ($meal->getItems() as $item) {
            $items[] = [
                'id' => $item->getId()?->toRfc4122(),
                'name' => $item->getName(),
                'brand' => $item->getBrand(),
                'amountGrams' => $item->getAmountGrams(),
                'calories' => $item->getCalories(),
                'proteins' => $item->getProteins(),
                'carbs' => $item->getCarbs(),
                'fats' => $item->getFats(),
                'servingId' => $item->getServing()?->getId()?->toRfc4122(),
            ];
        }

        return [
            'id' => $meal->getId()?->toRfc4122(),
            'name' => $meal->getName(),
            'preferredMealType' => $meal->getPreferredMealType(),
            'totalCalories' => $meal->getTotalCalories(),
            'totalProteins' => $meal->getTotalProteins(),
            'totalCarbs' => $meal->getTotalCarbs(),
            'totalFats' => $meal->getTotalFats(),
            'createdAt' => $meal->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $meal->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'items' => $items,
        ];
    }
}
