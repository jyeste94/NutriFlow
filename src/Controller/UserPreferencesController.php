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
        $prefs = $row ? json_decode($row['preferences'], true, 512, JSON_THROW_ON_ERROR) : [];
        $prefs = is_array($prefs) ? $prefs : [];

        return $this->json(array_replace([
            'calorie_goal' => null,
            'protein_goal' => null,
            'carbs_goal' => null,
            'fat_goal' => null,
            'gender' => null,
            'age' => null,
            'weight_kg' => null,
            'height_cm' => null,
            'activity' => null,
            'energy_goal' => null,
            'calorie_adjustment' => null,
        ], $prefs, [
            'maintenance_calories' => $this->calculateMaintenance($prefs),
            'roles' => $user->getRoles(),
        ]));
    }

    #[Route('/preferences', name: 'save_preferences', methods: ['PUT'])]
    public function savePreferences(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'El cuerpo JSON no es válido.'], 400);
        }
        if (!$payload instanceof \stdClass) {
            return $this->json(['error' => 'Las preferencias deben ser un objeto JSON.'], 400);
        }
        $changes = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // Roles and expenditure are server-owned, never mass-assigned from preferences.
        unset($changes['roles'], $changes['maintenance_calories']);
        $ranges = [
            'calorie_goal' => [0, 10000], 'protein_goal' => [0, 2500],
            'carbs_goal' => [0, 2500], 'fat_goal' => [0, 1200],
            'age' => [18, 120], 'weight_kg' => [20, 400], 'height_cm' => [100, 250],
            'calorie_adjustment' => [-1000, 1000],
        ];
        foreach ($ranges as $field => [$min, $max]) {
            if (!array_key_exists($field, $changes) || $changes[$field] === null) {
                continue;
            }
            $value = $changes[$field];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max
                || (in_array($field, ['age', 'calorie_adjustment'], true) && floor((float) $value) !== (float) $value)) {
                return $this->json(['error' => "$field debe ser un número válido entre $min y $max."], 400);
            }
        }
        if (isset($changes['gender']) && !in_array($changes['gender'], ['male', 'female'], true)) {
            return $this->json(['error' => 'Selecciona un sexo válido para la fórmula.'], 400);
        }
        if (isset($changes['activity']) && ((!is_int($changes['activity']) && !is_float($changes['activity']))
            || !in_array((float) $changes['activity'], [1.2, 1.375, 1.55, 1.725, 1.9], true))) {
            return $this->json(['error' => 'Selecciona un factor de actividad válido.'], 400);
        }
        if (isset($changes['energy_goal']) && !in_array($changes['energy_goal'], ['maintain', 'lose', 'gain'], true)) {
            return $this->json(['error' => 'El objetivo debe ser mantener, perder o ganar.'], 400);
        }
        $user = $this->getUser();
        $userId = $user->getId()->toRfc4122();
        $existing = $em->getConnection()->fetchAssociative(
            'SELECT id, preferences FROM user_preferences WHERE user_id = ?', [$userId]
        );
        $stored = $existing ? json_decode($existing['preferences'], true, 512, JSON_THROW_ON_ERROR) : [];
        $stored = is_array($stored) ? $stored : [];
        $data = array_replace($stored, $changes);
        unset($data['roles']);
        $maintenance = $this->calculateMaintenance($data);
        $data['maintenance_calories'] = $maintenance;

        // A manual intake target replaces the calculated goal rather than claiming a false adjustment.
        if (array_key_exists('calorie_goal', $changes) && !array_key_exists('energy_goal', $changes)
            && $changes['calorie_goal'] !== ($stored['calorie_goal'] ?? null)) {
            $data['energy_goal'] = null;
            $data['calorie_adjustment'] = null;
        }
        $goal = $data['energy_goal'] ?? null;
        if ($goal !== null) {
            $adjustment = $data['calorie_adjustment'] ?? null;
            if ($maintenance === null || (!is_int($adjustment) && !is_float($adjustment))
                || !is_finite((float) $adjustment) || floor((float) $adjustment) !== (float) $adjustment
                || ($goal === 'maintain' && $adjustment != 0)
                || ($goal === 'lose' && ($adjustment >= 0 || $adjustment < -1000))
                || ($goal === 'gain' && ($adjustment <= 0 || $adjustment > 1000))
                || !in_array($goal, ['maintain', 'lose', 'gain'], true)) {
                return $this->json(['error' => 'Completa los datos adultos y el ajuste: mantener 0, perder entre -1000 y -1, ganar entre 1 y 1000 kcal/día.'], 400);
            }
            $target = $maintenance + (int) $adjustment;
            if ($target <= 0 || $target > 10000) {
                return $this->json(['error' => 'El objetivo de ingesta resultante debe ser mayor que 0 y no superar 10000 kcal/día.'], 400);
            }
            $data['calorie_goal'] = $target;
        } elseif (array_key_exists('calorie_adjustment', $changes) && $changes['calorie_adjustment'] !== null) {
            return $this->json(['error' => 'Selecciona un objetivo antes de guardar un ajuste energético.'], 400);
        } else {
            $data['calorie_adjustment'] = null;
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if ($existing) {
            $em->getConnection()->executeStatement(
                'UPDATE user_preferences SET preferences = ? WHERE user_id = ?', [$json, $userId]
            );
        } else {
            $em->getConnection()->executeStatement(
                'INSERT INTO user_preferences (id, user_id, preferences) VALUES (?, ?, ?)',
                [new UuidV7(), $userId, $json]
            );
        }
        return $this->json(array_replace($data, ['roles' => $user->getRoles()]));
    }

    private function calculateMaintenance(array $prefs): ?int
    {
        if (!in_array($prefs['gender'] ?? null, ['male', 'female'], true)) {
            return null;
        }
        foreach (['age' => [18, 120], 'weight_kg' => [20, 400], 'height_cm' => [100, 250]] as $field => [$min, $max]) {
            $value = $prefs[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
                return null;
            }
        }
        $activity = $prefs['activity'] ?? null;
        if (floor((float) $prefs['age']) !== (float) $prefs['age']
            || (!is_int($activity) && !is_float($activity))
            || !in_array((float) $activity, [1.2, 1.375, 1.55, 1.725, 1.9], true)) {
            return null;
        }
        // Habitual exercise is already represented by the activity factor.
        $basal = 10 * $prefs['weight_kg'] + 6.25 * $prefs['height_cm'] - 5 * $prefs['age']
            + ($prefs['gender'] === 'male' ? 5 : -161);
        return $basal > 0 ? (int) round($basal * $activity) : null;
    }
}
