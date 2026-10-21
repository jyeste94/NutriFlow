<?php

namespace App\Tests\Api;

use App\Entity\MealEntry;
use App\Entity\Serving;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

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
                    'amount' => 60,
                    'unit' => 'g',
                    'calories' => 225,
                    'proteins' => 8,
                    'carbs' => 36,
                    'fats' => 4,
                ],
                [
                    'name' => 'Aceite Oliva Virgen Extra',
                    'brand' => 'Mercadona',
                    'amount' => 5,
                    'unit' => 'ml',
                    'calories' => 40,
                    'proteins' => 0,
                    'carbs' => 0,
                    'fats' => 4.5,
                ],
            ],
        ], $headers);

        $this->assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse();
        $this->assertSame('Desayuno Fitness', $data['name']);
        $this->assertSame('breakfast', $data['preferredMealType']);
        $this->assertEquals(265.0, $data['totalCalories']);
        $this->assertEquals(8.0, $data['totalProteins']);
        $this->assertEquals(36.0, $data['totalCarbs']);
        $this->assertEquals(8.5, $data['totalFats']);
        $this->assertCount(2, $data['items']);
        $this->assertEquals(60, $data['items'][0]['amount']);
        $this->assertSame('g', $data['items'][0]['unit']);
        $this->assertEquals(5, $data['items'][1]['amount']);
        $this->assertSame('ml', $data['items'][1]['unit']);

        // Test GET list
        $this->client->request('GET', '/v1/saved-meals', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame('Desayuno Fitness', $list[0]['name']);
        $this->assertSame($data['items'], $list[0]['items']);

        $this->client->request('GET', '/v1/saved-meals/' . $data['id'], [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame($data, $this->jsonResponse());

        foreach (['2026-10-22' => 1, '2026-10-23' => 0.5] as $date => $servings) {
            $registration = [
                'client_id' => $servings === 1 ? '1014cc1d-781f-4a18-8b23-d486a24016b4' : '9e5fd0b1-3452-49e3-9334-f52672f50767',
                'servings' => $servings,
            ];
            $this->client->jsonRequest('POST', '/v1/diaries/' . $date . '/saved-meals/' . $data['id'], $registration, $headers);
            $this->assertResponseStatusCodeSame(201);
            $this->client->jsonRequest('POST', '/v1/diaries/' . $date . '/saved-meals/' . $data['id'], $registration, $headers);
            $this->assertResponseStatusCodeSame(200);
            $this->assertSame(0, $this->jsonResponse()['entriesAdded']);
            $this->client->request('GET', '/v1/diaries/' . $date, [], [], $headers);
            $this->assertResponseIsSuccessful();
            $diary = $this->jsonResponse();
            $this->assertCount(2, $diary['entries']);
            foreach (['calories' => 'totalCalories', 'proteins' => 'totalProteins', 'carbs' => 'totalCarbs', 'fats' => 'totalFats'] as $field => $total) {
                $this->assertEquals($data[$total] * $servings, $diary[$total]);
            }
            $entries = array_column($diary['entries'], null, 'unit');
            foreach ($data['items'] as $item) {
                $entry = $entries[$item['unit']];
                $this->assertSame('breakfast', $entry['mealType']);
                $this->assertSame($item['name'], $entry['food']['name']);
                $this->assertEquals($item['amount'] * $servings, $entry['amount']);
                foreach (['calories', 'proteins', 'carbs', 'fats'] as $field) {
                    $this->assertEquals($item[$field] * $servings, $entry[$field]);
                }
            }
        }
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
                    'amount' => 150,
                    'unit' => 'g',
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
                    'amount' => 150,
                    'unit' => 'g',
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
                    'amount' => 150,
                    'unit' => 'g',
                    'calories' => 78,
                    'proteins' => 0.5,
                    'carbs' => 18,
                    'fats' => 0.2,
                ],
                [
                    'name' => 'Nueces',
                    'amount' => 30,
                    'unit' => 'g',
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
                    'amount' => 100,
                    'unit' => 'g',
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
                    'amount' => 250,
                    'unit' => 'g',
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
                ['name' => 'Vinculado', 'servingId' => $serving->getId()?->toRfc4122(), 'amount' => 200, 'unit' => 'g', 'calories' => 1],
                ['name' => 'Manual', 'amount' => 200, 'unit' => 'g', 'calories' => 200, 'proteins' => 20, 'carbs' => 40, 'fats' => 10],
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
            ['name' => 'Yogur', 'amount' => 150, 'unit' => 'g', 'calories' => 100, 'proteins' => 10, 'carbs' => 10, 'fats' => 2],
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
            'name' => 'Original', 'items' => [['name' => 'Avena', 'amount' => 50, 'unit' => 'g', 'calories' => 200, 'proteins' => 5, 'carbs' => 30, 'fats' => 3]],
        ], $headers);
        $id = $this->jsonResponse()['id'];
        $this->client->jsonRequest('PUT', '/v1/saved-meals/' . $id, [
            'name' => 'No guardar', 'items' => [['name' => 'Inválido', 'amount' => -10, 'unit' => 'g']],
        ], $headers);
        $this->assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/v1/saved-meals/' . $id, [], [], $headers);
        $meal = $this->jsonResponse();
        $this->assertSame('Original', $meal['name']);
        $this->assertSame('Avena', $meal['items'][0]['name']);
        $this->assertEquals(200, $meal['totalCalories']);
    }

    public static function metricServingUnits(): array
    {
        return [
            'grams' => ['grams', 'g'],
            'ml' => ['ml', 'ml'],
            'mixed case millilitres' => ['mL', 'ml'],
            'millilitres' => ['millilitres', 'ml'],
            'Spanish millilitres' => [' mililitros ', 'ml'],
        ];
    }

    #[DataProvider('metricServingUnits')]
    public function testLinkedMetricServingCalculatesNutritionInItsOwnUnit(string $servingUnit, string $unit): void
    {
        $source = $this->createServingFixture('metric-saved-serving');
        $source->setAmount(20);
        $source->setUnit($servingUnit);
        $source->setCalories(180);
        $source->setProteins(2);
        $source->setCarbs(4);
        $source->setFats(20);
        $this->em->flush();
        $sourceId = $source->getId()->toRfc4122();
        $headers = $this->authHeaders('metric-saved-meal');
        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Desayuno con aceite',
            'items' => [[
                'name' => 'Aceite Oliva Virgen Extra', 'servingId' => $sourceId,
                'amount' => 5, 'unit' => $unit, 'calories' => 999, 'proteins' => 999, 'carbs' => 999, 'fats' => 999,
            ]],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $meal = $this->jsonResponse();
        $item = $meal['items'][0];
        $this->assertEquals(5, $item['amount']);
        $this->assertSame($unit, $item['unit']);
        $this->assertEquals(20, $item['baseServingAmount']);
        $this->assertEquals(45, $item['calories']);
        $this->assertEquals(0.5, $item['proteins']);
        $this->assertEquals(1, $item['carbs']);
        $this->assertEquals(5, $item['fats']);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-22/saved-meals/' . $meal['id'], ['servings' => 0.5], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/v1/diaries/2026-10-22', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $entry = $this->jsonResponse()['entries'][0];
        $this->assertSame($sourceId, $entry['serving']['id']);
        $this->assertSame($servingUnit, $entry['unit']);
        $this->assertEquals(2.5, $entry['amount']);
        $this->assertEquals(22.5, $entry['calories']);
        $this->assertEquals(0.25, $entry['proteins']);
        $this->assertEquals(0.5, $entry['carbs']);
        $this->assertEquals(2.5, $entry['fats']);
    }

    public function testMetricUnitMismatchesDoNotCreateOrReplaceSavedIngredients(): void
    {
        $sourceIds = [];
        foreach (['g', 'ml'] as $sourceUnit) {
            $source = $this->createServingFixture('unit-mismatch-' . $sourceUnit);
            $source->setAmount(20);
            $source->setUnit($sourceUnit);
            $sourceIds[$sourceUnit] = $source->getId()->toRfc4122();
        }
        $this->em->flush();
        $headers = $this->authHeaders('saved-unit-mismatch');
        $original = ['name' => 'Avena', 'amount' => 60, 'unit' => 'g', 'calories' => 225, 'proteins' => 8, 'carbs' => 36, 'fats' => 4];
        $this->client->jsonRequest('POST', '/v1/saved-meals', ['name' => 'Original', 'items' => [$original]], $headers);
        $this->assertResponseStatusCodeSame(201);
        $meal = $this->jsonResponse();
        foreach (['g' => 'ml', 'ml' => 'g'] as $sourceUnit => $requestedUnit) {
            $invalid = ['name' => 'No convertir', 'servingId' => $sourceIds[$sourceUnit], 'amount' => 5, 'unit' => $requestedUnit];
            $this->client->jsonRequest('POST', '/v1/saved-meals', ['name' => 'No crear', 'items' => [$invalid]], $headers);
            $this->assertResponseStatusCodeSame(400);
            $this->client->jsonRequest('PUT', '/v1/saved-meals/' . $meal['id'], ['name' => 'No reemplazar', 'items' => [$original, $invalid]], $headers);
            $this->assertResponseStatusCodeSame(400);
            $this->client->request('GET', '/v1/saved-meals/' . $meal['id'], [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame($meal, $this->jsonResponse());
        }
        $this->client->request('GET', '/v1/saved-meals', [], [], $headers);
        $this->assertCount(1, $this->jsonResponse());
    }

    public static function incompatibleSavedServingReferences(): array
    {
        return [
            'deleted serving' => [null, null, true],
            'missing amount' => [null, 'ml', false],
            'zero amount' => [0.0, 'ml', false],
            'grams instead of millilitres' => [100.0, 'g', false],
            'unknown unit' => [100.0, 'portion', false],
        ];
    }

    #[DataProvider('incompatibleSavedServingReferences')]
    public function testUnavailableMetricReferenceRegistersItsPrivateMillilitreSnapshot(?float $amount, ?string $unit, bool $deleted): void
    {
        $source = $this->createServingFixture('saved-oil-snapshot');
        $source->setAmount(20);
        $source->setUnit('ml');
        $source->setCalories(180);
        $source->setProteins(2);
        $source->setCarbs(4);
        $source->setFats(20);
        $this->em->flush();
        $sourceId = $source->getId()->toRfc4122();
        $headers = $this->authHeaders('saved-oil-snapshot-owner');
        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Aceite guardado',
            'items' => [['name' => 'Aceite Oliva Virgen Extra', 'brand' => 'Mercadona', 'servingId' => $sourceId, 'amount' => 5, 'unit' => 'ml']],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $meal = $this->jsonResponse();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $reference = $em->find(Serving::class, $sourceId);
        if ($deleted) {
            // Exercise the ON DELETE SET NULL constraint; SQLite disables foreign keys by default.
            $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
            $em->remove($reference);
        } else {
            $reference->setAmount($amount);
            $reference->setUnit($unit);
            $reference->setCalories(999);
            $reference->setProteins(999);
            $reference->setCarbs(999);
            $reference->setFats(999);
        }
        $em->flush();
        $em->clear();
        $this->client->request('GET', '/v1/saved-meals/' . $meal['id'], [], [], $headers);
        $this->assertResponseIsSuccessful();
        $reloaded = $this->jsonResponse()['items'][0];
        $this->assertEquals(5, $reloaded['amount']);
        $this->assertSame('ml', $reloaded['unit']);
        $this->assertEquals(45, $reloaded['calories']);
        $this->assertNull($reloaded['servingId']);
        $this->assertNull($reloaded['baseServingAmount']);
        $registration = ['client_id' => '43f045ae-6579-4fa2-b625-9da9d2c72708', 'servings' => 0.5];
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-23/saved-meals/' . $meal['id'], $registration, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-23/saved-meals/' . $meal['id'], $registration, $headers);
        $this->assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/v1/diaries/2026-10-23', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $diary = $this->jsonResponse();
        $this->assertCount(1, $diary['entries']);
        $entry = $diary['entries'][0];
        $this->assertNotSame($sourceId, $entry['serving']['id']);
        $this->assertEquals(2.5, $entry['amount']);
        $this->assertSame('ml', $entry['unit']);
        $this->assertSame('Aceite Oliva Virgen Extra', $entry['food']['name']);
        $this->assertSame('Mercadona', $entry['food']['brand']);
        foreach (['calories' => 22.5, 'proteins' => 0.25, 'carbs' => 0.5, 'fats' => 2.5] as $field => $value) {
            $this->assertEquals($value, $entry[$field]);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $em->find(MealEntry::class, $entry['id']);
        $this->assertSame('saved-oil-snapshot-owner', $stored->getServing()->getFood()->getUser()->getFirebaseUid());
    }

}
