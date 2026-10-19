<?php

namespace App\Tests\Api;

final class FoodValidationTest extends ApiTestCase
{
    /// <summary>Verifies both food creation routes reject invalid nutrients and portions without writing rows.</summary>
    public function testCreationRejectsInvalidNutrition(): void
    {
        $headers = $this->authHeaders('nutrition-validation');
        foreach (['/v1/foods', '/v1/foods/custom'] as $route) {
            foreach (['calories' => -100, 'proteins' => -20, 'carbs' => 'bad', 'fats' => null, 'amountGrams' => 0, 'amount' => -10, 'name' => '   '] as $field => $value) {
                $this->client->jsonRequest('POST', $route, array_replace(['name' => 'Food', 'calories' => 120], [$field => $value]), $headers);
                $this->assertResponseStatusCodeSame(400);
            }
        }
        $this->client->request('GET', '/v1/foods/custom', [], [], $headers);
        self::assertSame([], $this->jsonResponse());
    }

    /// <summary>Verifies invalid edits preserve the last valid food values.</summary>
    public function testInvalidEditDoesNotChangeNutrition(): void
    {
        $headers = $this->authHeaders('nutrition-edit');
        $this->client->jsonRequest('POST', '/v1/foods/custom', ['name' => 'Food', 'calories' => 120], $headers);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];
        $this->client->jsonRequest('PUT', '/v1/foods/custom/' . $id, ['calories' => -1], $headers);
        $this->assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/v1/foods/' . $id, [], [], $headers);
        $this->assertResponseIsSuccessful();
        self::assertEquals(120, $this->jsonResponse()['servings'][0]['calories']);
    }

    /// <summary>Verifies inline diary foods enforce non-negative nutrition and positive portions.</summary>
    public function testInlineDiaryFoodRejectsInvalidNutrition(): void
    {
        $headers = $this->authHeaders('inline-nutrition');
        foreach (['calories' => -100, 'proteins' => -20, 'carbs' => 'bad', 'fats' => null, 'amountGrams' => 0] as $field => $value) {
            $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', array_replace(['mealType' => 'lunch', 'name' => 'Food', 'calories' => 120], [$field => $value]), $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/v1/diaries/2026-10-19', [], [], $headers);
        self::assertSame([], $this->jsonResponse()['entries']);
    }
}
