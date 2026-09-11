<?php

namespace App\Tests\Api;

use App\Entity\Food;
use App\Entity\Serving;
use App\Entity\UserFavoriteFood;
use App\Entity\User;

final class FoodNutritionApiTest extends ApiTestCase
{
    public function testNormalFoodWithServingReturnsCorrectMacros(): void
    {
        $headers = $this->authHeaders('food-test-user-1');
        $serving = $this->createServingFixture('ext-normal-food');

        $this->client->request('GET', '/v1/foods/favorites', [], [], $headers);
        $this->assertResponseIsSuccessful();

        // Mark as favorite
        $foodId = $serving->getFood()->getId()->toRfc4122();
        $this->client->request('POST', '/v1/foods/' . $foodId . '/favorite', [], [], $headers);
        $this->assertResponseIsSuccessful();

        // Retrieve favorites
        $this->client->request('GET', '/v1/foods/favorites', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $favs = $this->jsonResponse();
        $this->assertCount(1, $favs);
        $this->assertSame('Test Food', $favs[0]['name']);
        $this->assertTrue($favs[0]['hasNutritionInfo']);
        $this->assertSame(100.0, (float) $favs[0]['calories']);
        $this->assertSame(10.0, (float) $favs[0]['proteins']);
    }

    public function testLegitimateZeroKcalFoodPreservesZero(): void
    {
        $headers = $this->authHeaders('food-test-user-2');

        // Create a 0 kcal food (e.g. Agua / Agua mineral)
        $food = new Food();
        $food->setExternalId('ext-water');
        $food->setName('Agua Mineral');
        $food->setBrand('Natural');
        $food->setLastFetchedAt(new \DateTimeImmutable('now'));
        $food->setUpdatedAt(new \DateTimeImmutable('now'));

        $serving = new Serving();
        $serving->setFood($food);
        $serving->setDescription('250ml');
        $serving->setAmount(250.0);
        $serving->setCalories(0.0);
        $serving->setProteins(0.0);
        $serving->setCarbs(0.0);
        $serving->setFats(0.0);

        $food->addServing($serving);
        $this->em->persist($food);
        $this->em->persist($serving);
        $this->em->flush();

        $foodId = $food->getId()->toRfc4122();
        $this->client->request('POST', '/v1/foods/' . $foodId . '/favorite', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/foods/favorites', [], [], $headers);
        $favs = $this->jsonResponse();
        $this->assertCount(1, $favs);
        $this->assertSame('Agua Mineral', $favs[0]['name']);
        $this->assertTrue($favs[0]['hasNutritionInfo']);
        $this->assertSame(0.0, (float) $favs[0]['calories']);
        $this->assertSame(0.0, (float) $favs[0]['proteins']);
    }

    public function testFoodWithoutServingDoesNotInventMacros(): void
    {
        $headers = $this->authHeaders('food-test-user-3');

        // Food without any serving
        $food = new Food();
        $food->setExternalId('ext-no-serving');
        $food->setName('Alimento Sin Ración');
        $food->setLastFetchedAt(new \DateTimeImmutable('now'));
        $food->setUpdatedAt(new \DateTimeImmutable('now'));
        $this->em->persist($food);
        $this->em->flush();

        $foodId = $food->getId()->toRfc4122();
        $this->client->request('POST', '/v1/foods/' . $foodId . '/favorite', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/foods/favorites', [], [], $headers);
        $favs = $this->jsonResponse();
        $this->assertCount(1, $favs);
        $this->assertSame('Alimento Sin Ración', $favs[0]['name']);
        $this->assertFalse($favs[0]['hasNutritionInfo']);
        $this->assertNull($favs[0]['calories']);
        $this->assertNull($favs[0]['servingId']);
    }

    public function testRoutineWithTargetWeightFlow(): void
    {
        $headers = $this->authHeaders('routine-weight-user');
        $exercise = $this->createExerciseFixture('Bench Press Target');
        $exerciseId = $exercise->getId()->toRfc4122();

        // Create routine with target_weight = 75.5 kg
        $this->client->jsonRequest(
            'POST',
            '/v1/routines',
            [
                'name' => 'Fuerza Pecho',
                'daysOfWeek' => [1, 3],
                'exercises' => [
                    [
                        'exercise_id' => $exerciseId,
                        'sets' => 4,
                        'reps' => 8,
                        'restSeconds' => 90,
                        'target_weight' => 75.5,
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $created = $this->jsonResponse();
        $routineId = $created['id'];

        // Get single routine and verify target_weight
        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $routine = $this->jsonResponse();
        $this->assertSame('Fuerza Pecho', $routine['name']);
        $this->assertSame(75.5, (float) $routine['exercises'][0]['targetWeight']);

        // Get list of routines and verify target_weight is present
        $this->client->request('GET', '/v1/routines', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame(75.5, (float) $list[0]['exercises'][0]['targetWeight']);

        // Update target_weight to 80.0 kg
        $this->client->jsonRequest(
            'PUT',
            '/v1/routines/' . $routineId,
            [
                'name' => 'Fuerza Pecho Pro',
                'exercises' => [
                    [
                        'exercise_id' => $exerciseId,
                        'sets' => 4,
                        'reps' => 8,
                        'restSeconds' => 90,
                        'target_weight' => 80.0,
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $updated = $this->jsonResponse();
        $this->assertSame(80.0, (float) $updated['exercises'][0]['targetWeight']);
    }
}