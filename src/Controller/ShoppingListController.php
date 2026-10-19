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
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT id, food_name, quantity, unit, checked, created_at FROM shopping_list_items WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$this->getUser()->getId()->toRfc4122()]
        );
        foreach ($rows as &$row) {
            $row['checked'] = (bool) $row['checked'];
        }
        return $this->json($rows);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if ($error = $this->validate($data)) {
            return $this->json(['error' => $error], 400);
        }
        $id = (new UuidV7())->toRfc4122();
        $item = [
            'id' => $id,
            'food_name' => trim($data['food_name']),
            'quantity' => $data['quantity'] ?? null,
            'unit' => $data['unit'] ?? null,
            'checked' => false,
        ];
        $em->getConnection()->executeStatement(
            'INSERT INTO shopping_list_items (id, user_id, food_name, quantity, unit) VALUES (?, ?, ?, ?, ?)',
            [$id, $this->getUser()->getId()->toRfc4122(), $item['food_name'], $item['quantity'], $item['unit']]
        );
        return $this->json($item, 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if ($error = $this->validate($data)) {
            return $this->json(['error' => $error], 400);
        }
        $connection = $em->getConnection();
        $userId = $this->getUser()->getId()->toRfc4122();
        $row = $connection->fetchAssociative('SELECT id, food_name, quantity, unit, checked FROM shopping_list_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            return $this->json(['error' => 'Alimento no encontrado'], 404);
        }
        $row['food_name'] = trim($data['food_name']);
        foreach (['quantity', 'unit'] as $field) {
            if (array_key_exists($field, $data)) $row[$field] = $data[$field];
        }
        $connection->executeStatement('UPDATE shopping_list_items SET food_name = ?, quantity = ?, unit = ? WHERE id = ? AND user_id = ?', [$row['food_name'], $row['quantity'], $row['unit'], $id, $userId]);
        $row['checked'] = (bool) $row['checked'];
        return $this->json($row);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['PATCH'])]
    public function toggle(string $id, EntityManagerInterface $em): JsonResponse
    {
        $connection = $em->getConnection();
        $userId = $this->getUser()->getId()->toRfc4122();
        $affected = $connection->executeStatement('UPDATE shopping_list_items SET checked = CASE WHEN checked = 1 THEN 0 ELSE 1 END WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$affected) {
            return $this->json(['error' => 'Alimento no encontrado'], 404);
        }
        $checked = $connection->fetchOne('SELECT checked FROM shopping_list_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $this->json(['checked' => (bool) $checked]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id, EntityManagerInterface $em): JsonResponse
    {
        $affected = $em->getConnection()->executeStatement(
            'DELETE FROM shopping_list_items WHERE id = ? AND user_id = ?',
            [$id, $this->getUser()->getId()->toRfc4122()]
        );
        if (!$affected) {
            return $this->json(['error' => 'Alimento no encontrado'], 404);
        }
        return $this->json(null, 204);
    }

    private function validate(mixed $data): ?string
    {
        if (!is_array($data) || !isset($data['food_name']) || !is_string($data['food_name']) || trim($data['food_name']) === '' || mb_strlen(trim($data['food_name'])) > 255) {
            return 'El alimento debe tener un nombre de entre 1 y 255 caracteres';
        }
        foreach (['quantity', 'unit'] as $field) {
            if (isset($data[$field]) && (!is_string($data[$field]) || mb_strlen($data[$field]) > 50)) {
                return 'La cantidad y la unidad deben ser textos de hasta 50 caracteres';
            }
        }
        return null;
    }
}
