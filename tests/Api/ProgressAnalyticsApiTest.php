<?php

namespace App\Tests\Api;

final class ProgressAnalyticsApiTest extends ApiTestCase
{
    public function testUnauthorizedReturns401(): void
    {
        $this->client->request('GET', '/v1/progress/summary');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/v1/progress/body');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/v1/progress/training');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/v1/progress/strength');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/v1/progress/nutrition');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSummaryEmptyGracefully(): void
    {
        $headers = $this->authHeaders('prog-user-empty');

        $this->client->request('GET', '/v1/progress/summary?period=4w', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $res = $this->jsonResponse();

        $this->assertSame('4w', $res['period']);
        $this->assertArrayHasKey('weight_trend', $res);
        $this->assertNull($res['weight_trend']['current_kg']);
        $this->assertSame(0, $res['weight_trend']['data_points']);

        $this->assertArrayHasKey('training_consistency', $res);
        $this->assertSame(0, $res['training_consistency']['total_workouts']);

        $this->assertArrayHasKey('nutrition_adherence', $res);
        $this->assertSame(0, $res['nutrition_adherence']['days_tracked']);

        $this->assertArrayHasKey('strength_progression', $res);
        $this->assertNull($res['strength_progression']['exercise_name']);
    }

    public function testBodyProgressTrendAndRollingAverage(): void
    {
        $headers = $this->authHeaders('prog-user-body');

        // Create 5 measurements across the last 10 days
        $today = new \DateTimeImmutable('today');
        $weights = [80.0, 79.8, 79.5, 79.2, 78.8];

        foreach ($weights as $i => $w) {
            $d = $today->modify(sprintf('-%d days', (4 - $i) * 2));
            $this->client->jsonRequest('POST', '/v1/measurements', [
                'date' => $d->format('Y-m-d'),
                'weight_kg' => $w,
                'body_fat_pct' => 18.0 - ($i * 0.2),
            ], $headers);
            $this->assertResponseStatusCodeSame(201);
        }

        $this->client->request('GET', '/v1/progress/body?period=4w&metric=weight_kg', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $res = $this->jsonResponse();

        $this->assertCount(5, $res['data_points']);
        $this->assertNotEmpty($res['trendline']); // >= 4 points computes rolling average
        $this->assertEquals(80.0, $res['summary']['initial_value']);
        $this->assertEquals(78.8, $res['summary']['current_value']);
        $this->assertEquals(-1.2, $res['summary']['delta']);
        $this->assertSame('down', $res['summary']['direction']);
    }

    public function testTrainingProgressWeeklyDistribution(): void
    {
        $headers = $this->authHeaders('prog-user-train');
        $exercise = $this->createExerciseFixture('Bench Press Prog');
        $exerciseId = $exercise->getId()?->toRfc4122();

        // Create 2 workout sessions
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $s1 = (string) $this->jsonResponse()['id'];

        $this->client->jsonRequest('POST', '/v1/workouts/' . $s1 . '/sets', [
            'exercise_id' => $exerciseId,
            'reps' => 10,
            'weight' => 80.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $s1, [
            'duration_minutes' => 60,
        ], $headers);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/progress/training?period=4w', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $res = $this->jsonResponse();

        $this->assertGreaterThanOrEqual(1, $res['summary']['total_workouts']);
        $this->assertGreaterThanOrEqual(60, $res['summary']['total_duration_minutes']);
        $this->assertGreaterThanOrEqual(800.0, $res['summary']['total_volume_kg']);
        $this->assertCount(4, $res['weekly_distribution']); // 4 weeks in 4w
    }

    public function testStrengthProgressEpleyFormulaAndPrs(): void
    {
        $headers = $this->authHeaders('prog-user-strength');
        $exercise = $this->createExerciseFixture('Deadlift Strength Test');
        $exerciseId = $exercise->getId()?->toRfc4122();

        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $sId = (string) $this->jsonResponse()['id'];

        // 100 kg x 5 reps -> e1RM = 100 * (1 + 5/30) = 100 * 1.1666... = 116.7 kg
        $this->client->jsonRequest('POST', '/v1/workouts/' . $sId . '/sets', [
            'exercise_id' => $exerciseId,
            'reps' => 5,
            'weight' => 100.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/progress/strength?period=4w&exercise_id=' . $exerciseId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $res = $this->jsonResponse();

        $this->assertNotNull($res['exercise']);
        $this->assertSame('Deadlift Strength Test', $res['exercise']['name']);
        $this->assertCount(1, $res['history']);
        $this->assertEquals(116.7, $res['history'][0]['e1rm']);
        $this->assertEquals(116.7, $res['summary']['current_e1rm']);
        $this->assertEquals(100.0, $res['summary']['max_weight']);
        $this->assertNotEmpty($res['prs']);
    }

    public function testNutritionAdherenceCalculation(): void
    {
        $headers = $this->authHeaders('prog-user-nutri');
        $today = new \DateTimeImmutable('today');

        // Set user preferences: 2000 kcal, 150g protein
        $this->client->jsonRequest('PUT', '/v1/user/preferences', [
            'calorie_goal' => 2000,
            'protein_goal' => 150,
        ], $headers);
        $this->assertResponseIsSuccessful();

        // Create a food fixture & diary entry for today
        $food = $this->createFoodFixture('Chicken Breast Pro', 'Atlos Kitchen');
        $serving = $food->getServings()->first();
        $servingId = $serving->getId()?->toRfc4122();

        // Add meal entry for today with serving
        $this->client->jsonRequest('POST', '/v1/diaries/' . $today->format('Y-m-d') . '/entries', [
            'serving_id' => $servingId,
            'mealType' => 'lunch',
            'multiplier' => 10, // ~1100 kcal, 170g protein
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/progress/nutrition?period=4w', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $res = $this->jsonResponse();

        $this->assertSame(2000, $res['targets']['calorie_goal']);
        $this->assertSame(150, $res['targets']['protein_goal']);
        $this->assertSame(1, $res['summary']['days_tracked']);
        $this->assertCount(1, $res['daily_logs']);
    }
}
