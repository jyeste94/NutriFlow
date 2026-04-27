<?php

namespace App\Controller;

use App\Entity\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/v1/errors', name: 'api_errors_')]
class ErrorLogController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        // Solo admin — por ahora cualquier usuario autenticado puede ver errores
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));
        $source = $request->query->get('source');

        $errors = $this->em->createQueryBuilder()
            ->select('e')
            ->from(ErrorLog::class, 'e')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if ($source) {
            $errors = array_filter($errors, function (ErrorLog $log) use ($source) {
                $ctx = $log->getContext();
                return ($ctx['source'] ?? null) === $source;
            });
        }

        $result = array_map(function (ErrorLog $log) {
            $ctx = $log->getContext();
            return [
                'id' => $log->getId()->toRfc4122(),
                'message' => $log->getMessage(),
                'stack_trace' => $log->getStackTrace(),
                'context' => [
                    'uri' => $ctx['uri'] ?? null,
                    'method' => $ctx['method'] ?? null,
                    'status_code' => $ctx['status_code'] ?? null,
                    'file' => $ctx['file'] ?? null,
                    'line' => $ctx['line'] ?? null,
                    'source' => $ctx['source'] ?? null,
                ],
                'created_at' => $log->getCreatedAt()->format('c'),
            ];
        }, $errors);

        return $this->json($result);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $log = $this->em->getRepository(ErrorLog::class)->find($id);
        if (!$log) return $this->json(['error' => 'Error log not found'], 404);

        return $this->json([
            'id' => $log->getId()->toRfc4122(),
            'message' => $log->getMessage(),
            'stack_trace' => $log->getStackTrace(),
            'context' => $log->getContext(),
            'created_at' => $log->getCreatedAt()->format('c'),
        ]);
    }
}
