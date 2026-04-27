<?php

namespace App\Controller;

use App\Entity\Measurement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/measurements', name: 'api_measurements_')]
class MeasurementController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));
        $offset = ($page - 1) * $limit;

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Measurement::class, 'm')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $measurements = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Measurement::class, 'm')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.date', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $result = array_map(function (Measurement $m) {
            return [
                'id' => $m->getId()->toRfc4122(),
                'date' => $m->getDate()->format('c'),
                'weight_kg' => $m->getWeightKg(),
                'body_fat_pct' => $m->getBodyFatPct(),
                'chest_cm' => $m->getChestCm(),
                'waist_cm' => $m->getWaistCm(),
                'hips_cm' => $m->getHipsCm(),
                'arm_cm' => $m->getArmCm(),
                'thigh_cm' => $m->getThighCm(),
                'calf_cm' => $m->getCalfCm(),
                'notes' => $m->getNotes(),
            ];
        }, $measurements);

        $response = $this->json($result);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Per-Page', (string) $limit);

        return $response;
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (!isset($data['weight_kg'])) {
            return $this->json(['error' => 'weight_kg is required'], 400);
        }

        $weightKg = filter_var($data['weight_kg'], FILTER_VALIDATE_FLOAT);
        if ($weightKg === false || $weightKg <= 0 || $weightKg > 500) {
            return $this->json(['error' => 'weight_kg must be between 0 and 500'], 400);
        }

        $measurement = new Measurement();
        $measurement->setUser($user);
        $measurement->setWeightKg((float) $weightKg);

        if (isset($data['date'])) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $data['date'])
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $data['date']);
            if ($parsed) {
                $measurement->setDate($parsed);
            }
        }

        if (isset($data['body_fat_pct'])) {
            $bf = filter_var($data['body_fat_pct'], FILTER_VALIDATE_FLOAT);
            if ($bf !== false) $measurement->setBodyFatPct((float) $bf);
        }

        foreach (['chest_cm', 'waist_cm', 'hips_cm', 'arm_cm', 'thigh_cm', 'calf_cm'] as $field) {
            if (isset($data[$field])) {
                $val = filter_var($data[$field], FILTER_VALIDATE_FLOAT);
                if ($val !== false) {
                    $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                    $measurement->$setter((float) $val);
                }
            }
        }

        if (isset($data['notes'])) {
            $measurement->setNotes((string) $data['notes']);
        }

        $this->em->persist($measurement);
        $this->em->flush();

        return $this->json([
            'message' => 'Measurement created successfully',
            'id' => $measurement->getId()->toRfc4122(),
        ], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $measurement = $this->em->getRepository(Measurement::class)->find($id);
        if (!$measurement) {
            return $this->json(['error' => 'Measurement not found'], 404);
        }
        if ($measurement->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (isset($data['date'])) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $data['date'])
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $data['date']);
            if ($parsed) {
                $measurement->setDate($parsed);
            }
        }

        if (isset($data['weight_kg'])) {
            $weightKg = filter_var($data['weight_kg'], FILTER_VALIDATE_FLOAT);
            if ($weightKg !== false) $measurement->setWeightKg((float) $weightKg);
        }

        if (isset($data['body_fat_pct'])) {
            $bf = filter_var($data['body_fat_pct'], FILTER_VALIDATE_FLOAT);
            if ($bf !== false) $measurement->setBodyFatPct((float) $bf);
        }

        foreach (['chest_cm', 'waist_cm', 'hips_cm', 'arm_cm', 'thigh_cm', 'calf_cm'] as $field) {
            if (isset($data[$field])) {
                $val = filter_var($data[$field], FILTER_VALIDATE_FLOAT);
                if ($val !== false) {
                    $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                    $measurement->$setter((float) $val);
                } else {
                    $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                    $measurement->$setter(null);
                }
            }
        }

        if (isset($data['notes'])) {
            $measurement->setNotes((string) $data['notes']);
        }

        $this->em->flush();

        return $this->json(['message' => 'Measurement updated successfully']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $measurement = $this->em->getRepository(Measurement::class)->find($id);
        if (!$measurement) {
            return $this->json(['error' => 'Measurement not found'], 404);
        }
        if ($measurement->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->em->remove($measurement);
        $this->em->flush();

        return $this->json(['message' => 'Measurement deleted successfully']);
    }

    /**
     * @return array<mixed>|JsonResponse
     */
    private function parseJsonBody(Request $request): array|JsonResponse
    {
        if ($request->getContent() === '') {
            return [];
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        return $data;
    }
}
