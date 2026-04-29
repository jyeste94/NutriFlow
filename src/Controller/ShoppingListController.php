<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;

#[Route('/v1/shopping-list', name: 'api_shopping_')]
class ShoppingListController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT id, food_name, quantity, unit, checked, created_at FROM shopping_list_items WHERE user_id = ? ORDER BY created_at DESC',
            [$user->getId()->toRfc4122()]
        );

        return $this->json($rows);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (empty($data['food_name'])) {
            return $this->json(['error' => 'food_name is required'], 400);
        }

        $user = $this->getUser();
        $id = new UuidV7();
        $em->getConnection()->executeStatement(
            'INSERT INTO shopping_list_items (id, user_id, food_name, quantity, unit) VALUES (?, ?, ?, ?, ?)',
            [$id, $user->getId()->toRfc4122(), $data['food_name'], $data['quantity'] ?? null, $data['unit'] ?? null]
        );

        return $this->json([
            'id' => $id->toRfc4122(),
            'food_name' => $data['food_name'],
            'quantity' => $data['quantity'] ?? null,
            'unit' => $data['unit'] ?? null,
            'checked' => false,
        ], 201);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['PATCH'])]
    public function toggle(string $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $row = $em->getConnection()->fetchAssociative(
            'SELECT checked FROM shopping_list_items WHERE id = ? AND user_id = ?',
            [$id, $user->getId()->toRfc4122()]
        );
        if (!$row) return $this->json(['error' => 'Not found'], 404);

        $new = $row['checked'] ? 0 : 1;
        $em->getConnection()->executeStatement(
            'UPDATE shopping_list_items SET checked = ? WHERE id = ?', [$new, $id]
        );

        return $this->json(['checked' => (bool)$new]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $em->getConnection()->executeStatement(
            'DELETE FROM shopping_list_items WHERE id = ? AND user_id = ?',
            [$id, $user->getId()->toRfc4122()]
        );
        return $this->json(null, 204);
    }
}
