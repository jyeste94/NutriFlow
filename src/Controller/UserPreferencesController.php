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

        if (!$row) {
            return $this->json([
                'calorie_goal' => null,
                'protein_goal' => 140,
                'carbs_goal' => 167,
                'fat_goal' => 58,
                'gender' => null,
                'age' => null,
                'weight_kg' => null,
                'height_cm' => null,
                'activity' => null,
            ]);
        }

        return $this->json(array_merge([
            'calorie_goal' => null,
            'protein_goal' => 140,
            'carbs_goal' => 167,
            'fat_goal' => 58,
            'gender' => null,
            'age' => null,
            'weight_kg' => null,
            'height_cm' => null,
            'activity' => null,
        ], $prefs));
    }

    #[Route('/preferences', name: 'save_preferences', methods: ['PUT'])]
    /// <summary>Validates and merges preference changes without discarding unrelated profile fields.</summary>
    /// <param name="request">Partial preference object.</param>
    /// <param name="em">Persistence manager.</param>
    /// <returns>The merged preferences or a validation error.</returns>
    public function savePreferences(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        if (!$payload instanceof \stdClass) {
            return $this->json(['error' => 'Preferences must be a JSON object'], 400);
        }
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        foreach (['calorie_goal', 'protein_goal', 'carbs_goal', 'fat_goal', 'age', 'weight_kg', 'height_cm', 'activity'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $value = filter_var($data[$field], FILTER_VALIDATE_FLOAT);
                if ($value === false || !is_finite($value) || $value < 0) {
                    return $this->json(['error' => "$field must be a non-negative number or null"], 400);
                }
            }
        }
        $user = $this->getUser();
        $userId = $user->getId()->toRfc4122();

        $existing = $em->getConnection()->fetchAssociative(
            'SELECT id, preferences FROM user_preferences WHERE user_id = ?', [$userId]
        );

        $stored = $existing ? json_decode($existing['preferences'], true, 512, JSON_THROW_ON_ERROR) : [];
        $data = array_replace(is_array($stored) ? $stored : [], $data);
        $json = json_encode($data, JSON_THROW_ON_ERROR);

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
