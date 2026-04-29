<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;

#[Route('/v1/user', name: 'api_user_')]
class UserPreferencesController extends AbstractController
{
    #[Route('/preferences', name: 'get_preferences', methods: ['GET'])]
    public function getPreferences(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $row = $em->getConnection()->fetchAssociative(
            'SELECT preferences FROM user_preferences WHERE user_id = ?',
            [$user->getId()->toRfc4122()]
        );

        $prefs = $row ? json_decode($row['preferences'], true) : [];

        return $this->json(array_merge([
            'calorie_goal' => 1754,
            'protein_goal' => 140,
            'carbs_goal' => 167,
            'fat_goal' => 58,
            'theme' => 'system',
            'widgets' => ['calories', 'meals', 'progress'],
            'fasting_enabled' => false,
            'fasting_window' => '16:8',
        ], $prefs));
    }

    #[Route('/preferences', name: 'save_preferences', methods: ['PUT'])]
    public function savePreferences(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $userId = $user->getId()->toRfc4122();

        $existing = $em->getConnection()->fetchAssociative(
            'SELECT id FROM user_preferences WHERE user_id = ?', [$userId]
        );

        $json = json_encode($data);

        if ($existing) {
            $em->getConnection()->executeStatement(
                'UPDATE user_preferences SET preferences = ? WHERE user_id = ?',
                [$json, $userId]
            );
        } else {
            $em->getConnection()->executeStatement(
                'INSERT INTO user_preferences (id, user_id, preferences) VALUES (?, ?, ?)',
                [new UuidV7(), $userId, $json]
            );
        }

        return $this->json($data);
    }
}
