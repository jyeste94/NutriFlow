<?php

namespace App\Tests\Api;

final class FoodApiTest extends ApiTestCase
{
    public function testFoodSearchAndDetail(): void
    {
        $food = $this->createFoodFixture('food-search-1', 'Greek Yogurt', 'Protein Co');
        $foodId = $food->getId()?->toRfc4122();
        $this->assertNotNull($foodId);

        $headers = $this->authHeaders('food-user-1');

        $this->client->request('GET', '/v1/foods/search?q=greek', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $search = $this->jsonResponse();
        $this->assertCount(1, $search);
        $this->assertSame('Greek Yogurt', $search[0]['name']);

        $this->client->request('GET', '/v1/foods/' . $foodId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();
        $this->assertSame($foodId, $detail['id']);
        $this->assertCount(1, $detail['servings']);
    }

    public function testFoodSearchRequiresQueryAndDetailReturnsJson404(): void
    {
        $headers = $this->authHeaders('food-user-2');

        $this->client->request('GET', '/v1/foods/search', [], [], $headers);
        $this->assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/v1/foods/11111111-1111-1111-1111-111111111111', [], [], $headers);
        $this->assertResponseStatusCodeSame(404);
        $data = $this->jsonResponse();
        $this->assertSame('Food not found', $data['error'] ?? null);
    }
}
