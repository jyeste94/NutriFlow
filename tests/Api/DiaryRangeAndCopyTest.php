<?php

namespace App\Tests\Api;

use App\Entity\Food;
use App\Entity\MealDiary;
use App\Entity\MealEntry;
use App\Entity\SavedMeal;
use App\Entity\SavedMealItem;
use App\Entity\Serving;
use App\Entity\User;

final class DiaryRangeAndCopyTest extends ApiTestCase
{
    public function testDiariesRangeReturnsSummaryList(): void
    {
        $headers = $this->authHeaders('range-user-1');
        $serving = $this->createServingFixture('food-range-1');
        $servingId = $serving->getId()?->toRfc4122();

        // Add an entry on 2026-03-10
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-10/entries', [
            'serving_id' => $servingId,
            'mealType' => 'breakfast',
            'multiplier' => 1.5,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        // Add an entry on 2026-03-12
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-12/entries', [
            'serving_id' => $servingId,
            'mealType' => 'lunch',
            'multiplier' => 2.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        // Query range 2026-03-09 to 2026-03-13
        $this->client->request('GET', '/v1/diaries?from=2026-03-09&to=2026-03-13', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $data = $this->jsonResponse();
        $this->assertCount(2, $data);
        $this->assertSame('2026-03-10', $data[0]['date']);
        $this->assertEquals(150.0, $data[0]['totalCalories']);
        $this->assertSame(1, $data[0]['entriesCount']);

        $this->assertSame('2026-03-12', $data[1]['date']);
        $this->assertEquals(200.0, $data[1]['totalCalories']);
    }

    public function testCopyMealCopiesEntriesFromSourceToTarget(): void
    {
        $headers = $this->authHeaders('copy-user-1');
        $serving = $this->createServingFixture('food-copy-1');
        $servingId = $serving->getId()?->toRfc4122();

        // Log breakfast on 2026-03-05
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-05/entries', [
            'serving_id' => $servingId,
            'mealType' => 'breakfast',
            'multiplier' => 2.0,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        // 1-tap copy breakfast to 2026-03-06
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-06/copy-meal', [
            'sourceDate' => '2026-03-05',
            'mealType' => 'breakfast',
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        $copyResponse = $this->jsonResponse();
        $this->assertSame('Meal copied successfully', $copyResponse['message']);
        $this->assertSame(1, $copyResponse['copiedCount']);

        // Check target diary
        $this->client->request('GET', '/v1/diaries/2026-03-06', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $targetData = $this->jsonResponse();
        $this->assertCount(1, $targetData['entries']);
        $this->assertSame('breakfast', $targetData['entries'][0]['mealType']);
        $this->assertEquals(200.0, $targetData['totalCalories']);
    }

    public function testCopyMealReturns404IfNoSourceEntries(): void
    {
        $headers = $this->authHeaders('copy-user-2');

        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-06/copy-meal', [
            'sourceDate' => '2026-03-01',
            'mealType' => 'dinner',
        ], $headers);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddEntryWithInlineCustomFood(): void
    {
        $headers = $this->authHeaders('inline-user-1');

        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-15/entries', [
            'name' => 'Avocado Toast Quick Entry',
            'brand' => 'Homemade',
            'amountGrams' => 120,
            'calories' => 280,
            'proteins' => 6,
            'carbs' => 30,
            'fats' => 14,
            'mealType' => 'breakfast',
        ], $headers);

        $this->assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse();
        $this->assertSame('Entry added successfully', $data['message']);

        $this->client->request('GET', '/v1/diaries/2026-03-15', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $diaryData = $this->jsonResponse();
        $this->assertCount(1, $diaryData['entries']);
        $this->assertSame('Avocado Toast Quick Entry', $diaryData['entries'][0]['food']['name']);
        $this->assertEquals(280.0, $diaryData['totalCalories']);
    }

    public function testAddSavedMealToDiaryAppliesItemsAtomically(): void
    {
        $headers = $this->authHeaders('saved-combo-user');

        // Create a saved meal first
        $this->client->jsonRequest('POST', '/v1/saved-meals', [
            'name' => 'Almuerzo Proteico',
            'preferredMealType' => 'lunch',
            'items' => [
                [
                    'name' => 'Pechuga de pollo',
                    'amountGrams' => 200,
                    'calories' => 240,
                    'proteins' => 46,
                    'carbs' => 0,
                    'fats' => 4,
                ],
                [
                    'name' => 'Arroz blanco',
                    'amountGrams' => 150,
                    'calories' => 195,
                    'proteins' => 4,
                    'carbs' => 42,
                    'fats' => 0.5,
                ],
            ],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $comboId = $this->jsonResponse()['id'];

        // Add saved meal combo to diary for 2026-03-20
        $this->client->jsonRequest('POST', '/v1/diaries/2026-03-20/saved-meals/' . $comboId, [
            'mealType' => 'lunch',
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $addResponse = $this->jsonResponse();
        $this->assertSame(2, $addResponse['entriesAdded']);

        // Verify in diary
        $this->client->request('GET', '/v1/diaries/2026-03-20', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $diaryData = $this->jsonResponse();
        $this->assertCount(2, $diaryData['entries']);
        $this->assertEquals(435.0, $diaryData['totalCalories']);
        $this->assertEquals(50.0, $diaryData['totalProteins']);
    }
}
