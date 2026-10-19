<?php

namespace App\Tests\Api;

final class SavedMealApiTest extends ApiTestCase
{
    public function testCreateAndListSavedMeal(): void
    {
        $headers = $this->authHeaders('meal-combo-user-1');

        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Desayuno Fitness',
            'preferredMealType' => 'breakfast',
            'items' => [
                [
                    'name' => 'Copos de Avena',
                    'brand' => 'Mercadona',
                    'amountGrams' => 60,
                    'calories' => 225,
                    'proteins' => 8,
                    'carbs' => 36,
                    'fats' => 4,
                ],
                [
                    'name' => 'Proteína Whey',
                    'brand' => 'Optimum',
                    'amountGrams' => 30,
                    'calories' => 120,
                    'proteins' => 24,
                    'carbs' => 2,
                    'fats' => 1.5,
                ],
            ],
        ], $headers);

        $this->assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse();
        $this->assertSame('Desayuno Fitness', $data['name']);
        $this->assertSame('breakfast', $data['preferredMealType']);
        $this->assertEquals(345.0, $data['totalCalories']);
        $this->assertEquals(32.0, $data['totalProteins']);
        $this->assertCount(2, $data['items']);

        // Test GET list
        $this->client->request('GET', '/v1/saved-meals', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame('Desayuno Fitness', $list[0]['name']);
    }

    public function testGetSavedMealById(): void
    {
        $headers = $this->authHeaders('meal-combo-user-2');

        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Cena Ligera',
            'preferredMealType' => 'dinner',
            'items' => [
                [
                    'name' => 'Ensalada Mixta',
                    'amountGrams' => 150,
                    'calories' => 65,
                    'proteins' => 2,
                    'carbs' => 8,
                    'fats' => 2,
                ],
            ],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];

        $this->client->request('GET', '/v1/saved-meals/' . $id, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame('Cena Ligera', $data['name']);
    }

    public function testUpdateSavedMeal(): void
    {
        $headers = $this->authHeaders('meal-combo-user-3');

        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Merienda Inicial',
            'preferredMealType' => 'snack',
            'items' => [
                [
                    'name' => 'Manzana',
                    'amountGrams' => 150,
                    'calories' => 78,
                    'proteins' => 0.5,
                    'carbs' => 18,
                    'fats' => 0.2,
                ],
            ],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];

        // Update name and add another item
        $this->client->jsonRequest('PUT', '/v1/saved-meals/' . $id, [
            'name' => 'Merienda Completa',
            'items' => [
                [
                    'name' => 'Manzana',
                    'amountGrams' => 150,
                    'calories' => 78,
                    'proteins' => 0.5,
                    'carbs' => 18,
                    'fats' => 0.2,
                ],
                [
                    'name' => 'Nueces',
                    'amountGrams' => 30,
                    'calories' => 195,
                    'proteins' => 4.5,
                    'carbs' => 4,
                    'fats' => 19,
                ],
            ],
        ], $headers);
        $this->assertResponseIsSuccessful();
        $updated = $this->jsonResponse();
        $this->assertSame('Merienda Completa', $updated['name']);
        $this->assertCount(2, $updated['items']);
        $this->assertEquals(273.0, $updated['totalCalories']);
    }

    public function testDeleteSavedMeal(): void
    {
        $headers = $this->authHeaders('meal-combo-user-4');

        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Para Borrar',
            'items' => [
                [
                    'name' => 'Plátano',
                    'amountGrams' => 100,
                    'calories' => 89,
                    'proteins' => 1.1,
                    'carbs' => 22.8,
                    'fats' => 0.3,
                ],
            ],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];

        $this->client->request('DELETE', '/v1/saved-meals/' . $id, [], [], $headers);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/saved-meals/' . $id, [], [], $headers);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotAccessAnotherUsersSavedMeal(): void
    {
        $ownerHeaders = $this->authHeaders('combo-owner');
        $otherHeaders = $this->authHeaders('combo-stranger');

        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Combo Secreto',
            'items' => [
                [
                    'name' => 'Batido Proteína',
                    'amountGrams' => 250,
                    'calories' => 150,
                    'proteins' => 25,
                    'carbs' => 5,
                    'fats' => 2,
                ],
            ],
        ], $ownerHeaders);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];

        // Stranger tries to get it
        $this->client->request('GET', '/v1/saved-meals/' . $id, [], [], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);

        // Stranger tries to delete it
        $this->client->request('DELETE', '/v1/saved-meals/' . $id, [], [], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);
    }
    public function testLinkedAndInlineItemsRegisterTheirFullSavedAmounts(): void
    {
        $serving = $this->createServingFixture('combo-serving-100');
        $serving->setAmount(100);
        $serving->setUnit('g');
        $this->em->flush();
        $headers = $this->authHeaders('combo-portions');
        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Doble ración',
            'servings' => 2,
            'items' => [
                ['name' => 'Vinculado', 'servingId' => $serving->getId()?->toRfc4122(), 'amountGrams' => 200, 'calories' => 1],
                ['name' => 'Manual', 'amountGrams' => 200, 'calories' => 200, 'proteins' => 20, 'carbs' => 40, 'fats' => 10],
            ],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $meal = $this->jsonResponse();
        $this->assertEquals(400, $meal['totalCalories']);
        $registration = ['mealType' => 'lunch', 'client_id' => '59c4fe9f-089e-44f8-a67b-b3b0158a1422'];
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/saved-meals/' . $meal['id'], $registration, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/saved-meals/' . $meal['id'], $registration, $headers);
        $this->assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/v1/diaries/2026-10-19', [], [], $headers);
        $diary = $this->jsonResponse();
        $this->assertCount(2, $diary['entries']);
        $this->assertEquals(400, $diary['totalCalories']);
        $this->assertEquals(40, $diary['totalProteins']);
        foreach ($diary['entries'] as $entry) {
            $this->assertEquals(200, $entry['amount']);
            $this->assertEquals(200, $entry['calories']);
        }
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/saved-meals/' . $meal['id'], ['mealType' => 'lunch', 'servings' => 1], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/v1/diaries/2026-10-20', [], [], $headers);
        $portion = $this->jsonResponse();
        $this->assertEquals(200, $portion['totalCalories']);
        foreach ($portion['entries'] as $entry) $this->assertEquals(100, $entry['amount']);
    }

    public function testRetryingSavedMealCreationDoesNotDuplicateIt(): void
    {
        $headers = $this->authHeaders('combo-idempotency');
        $payload = ['client_id' => '2afc5b9c-6ed2-4ad3-9d77-162158c6b4ca', 'name' => 'Merienda', 'items' => [
            ['name' => 'Yogur', 'amountGrams' => 150, 'calories' => 100, 'proteins' => 10, 'carbs' => 10, 'fats' => 2],
        ]];
        $this->client->jsonRequest('POST', '/v1/saved-meals', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/saved-meals', $payload, $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/saved-meals', [], [], $headers);
        $this->assertCount(1, $this->jsonResponse());
        $this->client->jsonRequest('POST', '/v1/saved-meals', $payload, $this->authHeaders('another-combo-user'));
        $this->assertResponseStatusCodeSame(409);
    }

    public function testInvalidReplacementPreservesExistingIngredients(): void
    {
        $headers = $this->authHeaders('combo-atomic-update');
        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Original', 'items' => [['name' => 'Avena', 'amountGrams' => 50, 'calories' => 200, 'proteins' => 5, 'carbs' => 30, 'fats' => 3]],
        ], $headers);
        $id = $this->jsonResponse()['id'];
        $this->client->jsonRequest('PUT', '/v1/saved-meals/' . $id, [
            'name' => 'No guardar', 'items' => [['name' => 'Inválido', 'amountGrams' => -10]],
        ], $headers);
        $this->assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/v1/saved-meals/' . $id, [], [], $headers);
        $meal = $this->jsonResponse();
        $this->assertSame('Original', $meal['name']);
        $this->assertSame('Avena', $meal['items'][0]['name']);
        $this->assertEquals(200, $meal['totalCalories']);
    }

}
