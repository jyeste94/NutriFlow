<?php

namespace App\Tests\Api;

final class FoodRecentByMealTypeTest extends ApiTestCase
{
    public function testRecentFoodsAreFilteredByMealTypeAndDeduplicated(): void
    {
        $headersUserA = $this->authHeaders('meal-user-A');
        $headersUserB = $this->authHeaders('meal-user-B');

        $servingYogurt = $this->createServingFixture('food-yogurt-greek', 'Yogur Griego', 'Danone');
        $servingToast = $this->createServingFixture('food-toast-integral', 'Tostadas Integrales', 'Bimbo');
        $servingChicken = $this->createServingFixture('food-chicken-breast', 'Pechuga de Pollo', 'Granja');
        $servingSalmon = $this->createServingFixture('food-salmon-fillet', 'Salmón Fresco', 'Pescadería');

        $yogurtId = $servingYogurt->getId()?->toRfc4122();
        $toastId = $servingToast->getId()?->toRfc4122();
        $chickenId = $servingChicken->getId()?->toRfc4122();
        $salmonId = $servingSalmon->getId()?->toRfc4122();

        // User A logs for Breakfast:
        // Day 1: Toast (multiplier 1.0 = 100g)
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-25/entries', [
            'serving_id' => $toastId,
            'mealType' => 'breakfast',
            'multiplier' => 1.0,
        ], $headersUserA);
        $this->assertResponseStatusCodeSame(201);

        // Day 2: Yogurt (multiplier 2.5 = 250g)
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-26/entries', [
            'serving_id' => $yogurtId,
            'mealType' => 'breakfast',
            'multiplier' => 2.5,
        ], $headersUserA);
        $this->assertResponseStatusCodeSame(201);

        // Day 3: Yogurt again (multiplier 2.0 = 200g)
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-27/entries', [
            'serving_id' => $yogurtId,
            'mealType' => 'breakfast',
            'multiplier' => 2.0,
        ], $headersUserA);
        $this->assertResponseStatusCodeSame(201);

        // User A logs for Lunch:
        // Day 2: Chicken (multiplier 1.5 = 150g)
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-26/entries', [
            'serving_id' => $chickenId,
            'mealType' => 'lunch',
            'multiplier' => 1.5,
        ], $headersUserA);
        $this->assertResponseStatusCodeSame(201);

        // User A logs for Dinner:
        // Day 2: Salmon (multiplier 1.8 = 180g)
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-26/entries', [
            'serving_id' => $salmonId,
            'mealType' => 'dinner',
            'multiplier' => 1.8,
        ], $headersUserA);
        $this->assertResponseStatusCodeSame(201);

        // Day 3: Yogurt in dinner too! But with 1.2 multiplier = 120g
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-27/entries', [
            'serving_id' => $yogurtId,
            'mealType' => 'dinner',
            'multiplier' => 1.2,
        ], $headersUserA);
        $this->assertResponseStatusCodeSame(201);

        // User B logs in Breakfast:
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-27/entries', [
            'serving_id' => $chickenId,
            'mealType' => 'breakfast',
            'multiplier' => 3.0,
        ], $headersUserB);
        $this->assertResponseStatusCodeSame(201);

        // 1. Query Breakfast recents for User A
        $this->client->request('GET', '/v1/foods/recent?mealType=breakfast', [], [], $headersUserA);
        $this->assertResponseIsSuccessful();
        $breakfastRecents = $this->jsonResponse();

        // Should have Yogurt and Toast, exactly 2 items (no duplicates for yogurt despite 2 breakfast logs)
        $this->assertCount(2, $breakfastRecents);
        // Most recent is Yogurt (Day 3), then Toast (Day 1)
        $this->assertSame($servingYogurt->getFood()->getName(), $breakfastRecents[0]['name']);
        $this->assertSame($servingToast->getFood()->getName(), $breakfastRecents[1]['name']);
        // Habitual quantity for breakfast yogurt should be the latest one: 200g
        $this->assertEquals(200.0, $breakfastRecents[0]['lastUsedGrams']);
        // Neither chicken nor salmon must appear in breakfast recents
        $names = array_column($breakfastRecents, 'name');
        $this->assertNotContains($servingChicken->getFood()->getName(), $names);
        $this->assertNotContains($servingSalmon->getFood()->getName(), $names);

        // 2. Query Lunch recents for User A (using Spanish alias 'comida')
        $this->client->request('GET', '/v1/foods/recent?mealType=comida', [], [], $headersUserA);
        $this->assertResponseIsSuccessful();
        $lunchRecents = $this->jsonResponse();
        $this->assertCount(1, $lunchRecents);
        $this->assertSame($servingChicken->getFood()->getName(), $lunchRecents[0]['name']);
        $this->assertEquals(150.0, $lunchRecents[0]['lastUsedGrams']);

        // 3. Query Dinner recents for User A (using Spanish alias 'cena')
        $this->client->request('GET', '/v1/foods/recent?mealType=cena', [], [], $headersUserA);
        $this->assertResponseIsSuccessful();
        $dinnerRecents = $this->jsonResponse();
        // Has Yogurt (Day 3) and Salmon (Day 2)
        $this->assertCount(2, $dinnerRecents);
        $this->assertSame($servingYogurt->getFood()->getName(), $dinnerRecents[0]['name']);
        // Habitual quantity for dinner yogurt is 120g (different from breakfast's 200g!)
        $this->assertEquals(120.0, $dinnerRecents[0]['lastUsedGrams']);
        $this->assertSame($servingSalmon->getFood()->getName(), $dinnerRecents[1]['name']);
        $this->assertEquals(180.0, $dinnerRecents[1]['lastUsedGrams']);

        // 4. Query Snack recents for User A (empty state)
        $this->client->request('GET', '/v1/foods/recent?mealType=snack', [], [], $headersUserA);
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $this->jsonResponse());

        // 5. User B isolation
        $this->client->request('GET', '/v1/foods/recent?mealType=breakfast', [], [], $headersUserB);
        $this->assertResponseIsSuccessful();
        $userBRecents = $this->jsonResponse();
        $this->assertCount(1, $userBRecents);
        $this->assertSame($servingChicken->getFood()->getName(), $userBRecents[0]['name']);
        $this->assertEquals(300.0, $userBRecents[0]['lastUsedGrams']);

        // 6. Global recents (without mealType) returns all 4 foods for User A
        $this->client->request('GET', '/v1/foods/recent', [], [], $headersUserA);
        $this->assertResponseIsSuccessful();
        $globalRecents = $this->jsonResponse();
        $this->assertCount(4, $globalRecents);
    }

    public function testFrequentFoodsFilteredByMealType(): void
    {
        $headers = $this->authHeaders('frequent-meal-user');

        $servingOats = $this->createServingFixture('food-oats', 'Copos de Avena', 'Quaker');
        $servingEgg = $this->createServingFixture('food-egg', 'Huevos Camperos', 'Granja');

        $oatsId = $servingOats->getId()?->toRfc4122();
        $eggId = $servingEgg->getId()?->toRfc4122();

        // Breakfast: Oats 3 times, Egg 1 time
        for ($i = 1; $i <= 3; $i++) {
            $this->client->jsonRequest('POST', "/v1/diaries/2026-10-0{$i}/entries", [
                'serving_id' => $oatsId,
                'mealType' => 'breakfast',
                'multiplier' => 0.8, // 80g
            ], $headers);
            $this->assertResponseStatusCodeSame(201);
        }
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-04/entries', [
            'serving_id' => $eggId,
            'mealType' => 'breakfast',
            'multiplier' => 1.2, // 120g
        ], $headers);
        $this->assertResponseStatusCodeSame(201);

        // Dinner: Egg 5 times
        for ($i = 1; $i <= 5; $i++) {
            $this->client->jsonRequest('POST', "/v1/diaries/2026-10-1{$i}/entries", [
                'serving_id' => $eggId,
                'mealType' => 'dinner',
                'multiplier' => 2.0, // 200g
            ], $headers);
            $this->assertResponseStatusCodeSame(201);
        }

        // Query frequent breakfast: Oats should be #1 with 80g
        $this->client->request('GET', '/v1/foods/frequent?mealType=breakfast', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $frequentBreakfast = $this->jsonResponse();
        $this->assertCount(2, $frequentBreakfast);
        $this->assertSame($servingOats->getFood()->getName(), $frequentBreakfast[0]['name']);
        $this->assertEquals(80.0, $frequentBreakfast[0]['lastUsedGrams']);

        // Query frequent dinner: Egg should be #1 with 200g
        $this->client->request('GET', '/v1/foods/frequent?mealType=dinner', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $frequentDinner = $this->jsonResponse();
        $this->assertCount(1, $frequentDinner);
        $this->assertSame($servingEgg->getFood()->getName(), $frequentDinner[0]['name']);
        $this->assertEquals(200.0, $frequentDinner[0]['lastUsedGrams']);
    }
}
