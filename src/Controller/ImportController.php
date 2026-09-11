<?php

namespace App\Controller;

use App\Service\HevyImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/v1/import', name: 'api_import_')]
class ImportController extends AbstractController
{
    public function __construct(
        private HevyImportService $hevyImportService
    ) {}

    #[Route('/hevy', name: 'hevy', methods: ['POST'])]
    public function importHevy(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded. Send a CSV file in the "file" field.'], 400);
        }

        // Validate file extension
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'csv') {
            return $this->json(['error' => 'Invalid file type. Only CSV files are accepted.'], 400);
        }

        // Validate file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->json(['error' => 'File too large. Maximum size is 10MB.'], 413);
        }

        try {
            $csvContent = file_get_contents($file->getPathname());
            if ($csvContent === false || trim($csvContent) === '') {
                return $this->json(['error' => 'Could not read the uploaded file or file is empty.'], 400);
            }

            $result = $this->hevyImportService->importWorkoutCsv($user, $csvContent);

            if (!empty($result['errors']) && $result['sessions_created'] === 0) {
                return $this->json([
                    'error' => 'Import failed',
                    'details' => $result['errors'],
                ], 400);
            }

            return $this->json([
                'message' => 'Import completed successfully',
                'sessions_created' => $result['sessions_created'],
                'sets_imported' => $result['sets_imported'],
                'exercises_created' => $result['exercises_created'],
                'sessions_skipped' => $result['sessions_skipped'],
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'An unexpected error occurred during import.',
                'details' => [$e->getMessage()],
            ], 500);
        }
    }
}
