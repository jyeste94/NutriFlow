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
}
