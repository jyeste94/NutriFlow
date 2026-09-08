<?php

namespace App\Tests\Api;

final class FoodFavoritesAndCustomTest extends ApiTestCase
{
    public function testCreateCustomFoodAndList(): void
    {
        $headers = $this->authHeaders('custom-food-user-1');

        $this->client->jsonRequest('POST', '/v1/foods/custom', [
            'name' => 'Pan Proteico Casero',
            'brand' => 'Mi Cocina',
            'amountGrams' => 60,
            'calories' => 140,
            'proteins' => 12,
            'carbs' => 15,
            'fats' => 3,
        ], $headers);

        $this->assertResponseStatusCodeSame(201);
        $created = $this->jsonResponse();
        $this->assertSame('Pan Proteico Casero', $created['name']);
        $this->assertSame('Mi Cocina', $created['brand']);
        $this->assertEquals(140.0, $created['calories']);
        $this->assertEquals(12.0, $created['proteins']);

        // Check list
        $this->client->request('GET', '/v1/foods/custom', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame('Pan Proteico Casero', $list[0]['name']);
    }

    public function testUpdateCustomFood(): void
    {
        $headers = $this->authHeaders('custom-food-user-2');

        $this->client->jsonRequest('POST', '/v1/foods/custom', [
            'name' => 'Galletas Avena v1',
            'amountGrams' => 50,
            'calories' => 200,
            'proteins' => 5,
            'carbs' => 30,
            'fats' => 7,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];

        $this->client->jsonRequest('PUT', '/v1/foods/custom/' . $id, [
            'name' => 'Galletas Avena v2 (Bajo en grasa)',
            'calories' => 160,
            'fats' => 3,
        ], $headers);
        $this->assertResponseIsSuccessful();
        $updated = $this->jsonResponse();
        $this->assertSame('Galletas Avena v2 (Bajo en grasa)', $updated['name']);
        $this->assertEquals(160.0, $updated['calories']);
    }

    public function testDeleteCustomFood(): void
    {
        $headers = $this->authHeaders('custom-food-user-3');

        $this->client->jsonRequest('POST', '/v1/foods/custom', [
            'name' => 'Alimento Temporal',
            'calories' => 100,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];

        $this->client->request('DELETE', '/v1/foods/custom/' . $id, [], [], $headers);
        $this->assertResponseIsSuccessful();

        // Check list is empty
        $this->client->request('GET', '/v1/foods/custom', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(0, $list);
    }

    public function testAddAndRemoveFavorite(): void
    {
        $headers = $this->authHeaders('fav-user-1');
        $food = $this->createFoodFixture('ext-fav-1', 'Avena Suave', 'Hacendado');
        $foodId = $food->getId()?->toRfc4122();

        // Add to favorites
        $this->client->request('POST', '/v1/foods/' . $foodId . '/favorite', [], [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($this->jsonResponse()['isFavorite']);

        // Check favorites list
        $this->client->request('GET', '/v1/foods/favorites', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $favs = $this->jsonResponse();
        $this->assertCount(1, $favs);
        $this->assertSame('Avena Suave', $favs[0]['name']);
        $this->assertTrue($favs[0]['isFavorite']);

        // Remove from favorites
        $this->client->request('DELETE', '/v1/foods/' . $foodId . '/favorite', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->jsonResponse()['isFavorite']);

        // Check favorites list again
        $this->client->request('GET', '/v1/foods/favorites', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $favsAfter = $this->jsonResponse();
        $this->assertCount(0, $favsAfter);
    }

    public function testRecentAndFrequentFoodsCalculatedFromDiaryHistory(): void
    {
        $headers = $this->authHeaders('history-user-1');
        $servingA = $this->createServingFixture('ext-hist-A');
        $servingB = $this->createServingFixture('ext-hist-B');

        $servingAId = $servingA->getId()?->toRfc4122();
        $servingBId = $servingB->getId()?->toRfc4122();

        // Log serving A twice
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-01/entries', [
            'serving_id' => $servingAId,
            'mealType' => 'breakfast',
            'multiplier' => 1.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-02/entries', [
            'serving_id' => $servingAId,
            'mealType' => 'lunch',
            'multiplier' => 1.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        // Log serving B once
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-02/entries', [
            'serving_id' => $servingBId,
            'mealType' => 'dinner',
            'multiplier' => 1.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        // Query recents
        $this->client->request('GET', '/v1/foods/recent', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $recents = $this->jsonResponse();
        $this->assertGreaterThanOrEqual(2, count($recents));

        // Query frequents
        $this->client->request('GET', '/v1/foods/frequent', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $frequents = $this->jsonResponse();
        $this->assertGreaterThanOrEqual(2, count($frequents));
        // Serving A has 2 logs, so it should be first
        $this->assertSame($servingAId, $frequents[0]['servingId']);
    }
}
