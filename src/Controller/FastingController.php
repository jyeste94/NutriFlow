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
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT id, date, start_time, end_time, duration_hours, notes FROM fasting_logs WHERE user_id = ? ORDER BY date DESC, start_time DESC, id DESC LIMIT 30',
            [$this->getUser()->getId()->toRfc4122()]
        );
        return $this->json(array_map($this->serialize(...), $rows));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = $this->parseRecord($request);
        if (is_string($data)) return $this->json(['error' => $data], 400);
        $id = (new UuidV7())->toRfc4122();
        $em->getConnection()->executeStatement(
            'INSERT INTO fasting_logs (id, user_id, date, start_time, end_time, duration_hours, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $this->getUser()->getId()->toRfc4122(), $data['date'], $data['start_time'], $data['end_time'], $data['duration_hours'], $data['notes']]
        );
        return $this->json(['id' => $id] + $data, 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = $this->parseRecord($request);
        if (is_string($data)) return $this->json(['error' => $data], 400);
        $connection = $em->getConnection();
        $userId = $this->getUser()->getId()->toRfc4122();
        $existing = $connection->fetchAssociative('SELECT id, notes FROM fasting_logs WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$existing) return $this->json(['error' => 'Registro no encontrado'], 404);
        $input = json_decode($request->getContent(), true);
        if (!array_key_exists('notes', $input)) $data['notes'] = $existing['notes'];
        $connection->executeStatement(
            'UPDATE fasting_logs SET date = ?, start_time = ?, end_time = ?, duration_hours = ?, notes = ? WHERE id = ? AND user_id = ?',
            [$data['date'], $data['start_time'], $data['end_time'], $data['duration_hours'], $data['notes'], $id, $userId]
        );
        return $this->json(['id' => $id] + $data);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id, EntityManagerInterface $em): JsonResponse
    {
        $affected = $em->getConnection()->executeStatement(
            'DELETE FROM fasting_logs WHERE id = ? AND user_id = ?',
            [$id, $this->getUser()->getId()->toRfc4122()]
        );
        if (!$affected) return $this->json(['error' => 'Registro no encontrado'], 404);
        return $this->json(null, 204);
    }

    private function parseRecord(Request $request): array|string
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) return 'El registro debe ser un objeto JSON';
        foreach (['date', 'end_date', 'start_time', 'end_time'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) return 'Las fechas y horas de inicio y fin son obligatorias';
        }
        $zone = new \DateTimeZone('UTC');
        $startText = $data['date'].' '.$data['start_time'];
        $endText = $data['end_date'].' '.$data['end_time'];
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} ([01][0-9]|2[0-3]):[0-5][0-9]$/D', $startText)
            || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} ([01][0-9]|2[0-3]):[0-5][0-9]$/D', $endText)) {
            return 'Usa fechas válidas YYYY-MM-DD y horas HH:MM';
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startText, $zone);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endText, $zone);
        if (!$start || !$end || $start->format('Y-m-d H:i') !== $startText || $end->format('Y-m-d H:i') !== $endText) {
            return 'Usa fechas válidas YYYY-MM-DD y horas HH:MM';
        }
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0 || $seconds > 48 * 3600) return 'El fin debe ser posterior al inicio y el ayuno no puede superar 48 horas';
        $duration = round($seconds / 3600, 2);
        if (array_key_exists('duration_hours', $data) && (!is_numeric($data['duration_hours']) || abs((float) $data['duration_hours'] - $duration) > 0.005)) {
            return 'La duración no coincide con las fechas y horas';
        }
        if (isset($data['notes']) && (!is_string($data['notes']) || mb_strlen($data['notes']) > 5000)) return 'Las notas no pueden superar 5000 caracteres';
        return [
            'date' => $data['date'],
            'end_date' => $data['end_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'duration_hours' => $duration,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function serialize(array $row): array
    {
        $row['duration_hours'] = (float) $row['duration_hours'];
        $row['end_date'] = $row['date'];
        if ($row['start_time'] !== null) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $row['date'].' '.substr($row['start_time'], 0, 5), new \DateTimeZone('UTC'));
            if ($start) $row['end_date'] = $start->modify('+'.(int) round($row['duration_hours'] * 60).' minutes')->format('Y-m-d');
        }
        return $row;
    }
}
