<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;

#[Route('/v1/fasting', name: 'api_fasting_')]
class FastingController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT id, date, start_time, end_time, duration_hours, notes FROM fasting_logs WHERE user_id = ? ORDER BY date DESC LIMIT 30',
            [$user->getId()->toRfc4122()]
        );
        return $this->json($rows);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $id = new UuidV7();
        $em->getConnection()->executeStatement(
            'INSERT INTO fasting_logs (id, user_id, date, start_time, end_time, duration_hours, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $user->getId()->toRfc4122(),
                $data['date'] ?? date('Y-m-d'),
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
                $data['duration_hours'] ?? 0,
                $data['notes'] ?? null,
            ]
        );
        return $this->json(['id' => $id->toRfc4122()], 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $em->getConnection()->executeStatement(
            'DELETE FROM fasting_logs WHERE id = ? AND user_id = ?',
            [$id, $user->getId()->toRfc4122()]
        );
        return $this->json(null, 204);
    }
}
