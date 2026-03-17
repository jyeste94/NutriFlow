<?php

namespace App\Controller;

use App\Entity\Exercise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

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
        $total = (int) $repo->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $exercises = $repo->createQueryBuilder('e')
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
}
