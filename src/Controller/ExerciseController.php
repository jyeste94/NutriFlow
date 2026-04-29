<?php

namespace App\Controller;

use App\Entity\Exercise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[Route('/v1/exercises', name: 'api_exercises_')]
class ExerciseController extends AbstractController
{
    #[Route('', name: 'get_all', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));
        $offset = ($page - 1) * $limit;

        $repo = $em->getRepository(Exercise::class);
        $qb = $repo->createQueryBuilder('e');
        $countQb = $repo->createQueryBuilder('e')->select('COUNT(e.id)');

        $muscleGroup = $request->query->get('muscleGroup');
        $equipment = $request->query->get('equipment');

        if ($muscleGroup) {
            $qb->andWhere('e.muscleGroup = :muscleGroup');
            $qb->setParameter('muscleGroup', $muscleGroup);
            $countQb->andWhere('e.muscleGroup = :muscleGroup');
            $countQb->setParameter('muscleGroup', $muscleGroup);
        }

        if ($equipment) {
            $qb->andWhere('e.equipment = :equipment');
            $qb->setParameter('equipment', $equipment);
            $countQb->andWhere('e.equipment = :equipment');
            $countQb->setParameter('equipment', $equipment);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $exercises = $qb
            ->orderBy('e.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $result = array_map(function (Exercise $exercise) {
            return [
                'id' => $exercise->getId()->toRfc4122(),
                'name' => $exercise->getName(),
                'muscleGroup' => $exercise->getMuscleGroup(),
                'equipment' => $exercise->getEquipment(),
                'description' => $exercise->getDescription(),
                'gifUrl' => $exercise->getGifUrl(),
                'videoUrl' => $exercise->getVideoUrl(),
            ];
        }, $exercises);

        $response = $this->json($result);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Per-Page', (string) $limit);

        return $response;
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $q = $request->query->get('q', '');
        if (trim($q) === '') {
            return $this->json(['error' => 'Query parameter "q" is required'], 400);
        }

        $limit = min(50, max(1, $request->query->getInt('limit', 20)));

        $repo = $em->getRepository(Exercise::class);
        $exercises = $repo->createQueryBuilder('e')
            ->where('e.name LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('e.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $result = array_map(function (Exercise $exercise) {
            return [
                'id' => $exercise->getId()->toRfc4122(),
                'name' => $exercise->getName(),
                'muscleGroup' => $exercise->getMuscleGroup(),
                'equipment' => $exercise->getEquipment(),
                'description' => $exercise->getDescription(),
                'gifUrl' => $exercise->getGifUrl(),
                'videoUrl' => $exercise->getVideoUrl(),
            ];
        }, $exercises);

        return $this->json($result);
    }

    #[Route('/{id}', name: 'get_one', methods: ['GET'])]
    public function getOne(string $id, EntityManagerInterface $em): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invalid exercise ID format'], 400);
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, name, muscle_group, equipment, description, gif_url, video_url FROM exercises WHERE id = ?',
            [$id]
        );

        if (!$row) {
            return $this->json(['error' => 'Exercise not found'], 404);
        }

        return $this->json([
            'id' => $row['id'],
            'name' => $row['name'],
            'muscleGroup' => $row['muscle_group'],
            'equipment' => $row['equipment'],
            'description' => $row['description'],
            'gifUrl' => $row['gif_url'],
            'videoUrl' => $row['video_url'],
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return $this->json(['error' => 'Exercise name is required'], 400);
        }

        $exercise = new Exercise();
        $exercise->setId(new UuidV7());
        $exercise->setName($data['name']);
        $exercise->setMuscleGroup($data['muscleGroup'] ?? '');
        $exercise->setEquipment($data['equipment'] ?? null);
        $exercise->setDescription($data['description'] ?? null);
        $exercise->setGifUrl($data['gifUrl'] ?? null);
        $exercise->setVideoUrl($data['videoUrl'] ?? null);

        $em->persist($exercise);
        $em->flush();

        return $this->json([
            'id' => $exercise->getId()->toRfc4122(),
            'name' => $exercise->getName(),
            'muscleGroup' => $exercise->getMuscleGroup(),
            'equipment' => $exercise->getEquipment(),
            'description' => $exercise->getDescription(),
            'gifUrl' => $exercise->getGifUrl(),
            'videoUrl' => $exercise->getVideoUrl(),
        ], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invalid exercise ID format'], 400);
        }

        $repo = $em->getRepository(Exercise::class);
        $exercise = $repo->find($id);

        if (!$exercise) {
            return $this->json(['error' => 'Exercise not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) $exercise->setName($data['name']);
        if (isset($data['muscleGroup'])) $exercise->setMuscleGroup($data['muscleGroup']);
        if (array_key_exists('equipment', $data)) $exercise->setEquipment($data['equipment']);
        if (array_key_exists('description', $data)) $exercise->setDescription($data['description']);
        if (array_key_exists('gifUrl', $data)) $exercise->setGifUrl($data['gifUrl']);
        if (array_key_exists('videoUrl', $data)) $exercise->setVideoUrl($data['videoUrl']);

        $em->flush();

        return $this->json([
            'id' => $exercise->getId()->toRfc4122(),
            'name' => $exercise->getName(),
            'muscleGroup' => $exercise->getMuscleGroup(),
            'equipment' => $exercise->getEquipment(),
            'description' => $exercise->getDescription(),
            'gifUrl' => $exercise->getGifUrl(),
            'videoUrl' => $exercise->getVideoUrl(),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id, EntityManagerInterface $em): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invalid exercise ID format'], 400);
        }

        $repo = $em->getRepository(Exercise::class);
        $exercise = $repo->find($id);

        if (!$exercise) {
            return $this->json(['error' => 'Exercise not found'], 404);
        }

        $em->remove($exercise);
        $em->flush();

        return $this->json(null, 204);
    }
}
