<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\FoodRepository;

final class AccountPrivacyTest extends ApiTestCase
{
    /// <summary>Verifies foreign food references are denied across details, favorites, diaries, plans and saved meals.</summary>
    public function testPersonalFoodCannotBeReadOrReferencedByAnotherAccount(): void
    {
        $owner = $this->authHeaders('food-owner');
        $other = $this->authHeaders('food-other');
        $this->client->jsonRequest('POST', '/v1/foods/custom', ['name' => 'Private food', 'calories' => 120], $owner);
        $this->assertResponseStatusCodeSame(201);
        $food = $this->jsonResponse();
        $this->client->request('GET', '/v1/foods/' . $food['id'], [], [], $owner);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/foods/' . $food['id'], [], [], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/v1/foods/' . $food['id'] . '/favorite', [], [], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', ['serving_id' => $food['servingId'], 'mealType' => 'lunch'], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/v1/diet-plans', ['name' => 'Private reference', 'days' => [['day_of_week' => 'mon', 'meals' => [['serving_id' => $food['servingId'], 'meal_type' => 'lunch']]]]], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/v1/saved-meals', ['name' => 'Private reference', 'items' => [['name' => 'Private food', 'servingId' => $food['servingId']]]], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/v1/saved-meals', ['name' => 'Existing meal', 'items' => [['name' => 'Own item']]], $other);
        $this->assertResponseStatusCodeSame(201);
        $mealId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('PUT', '/v1/saved-meals/' . $mealId, ['items' => [['name' => 'Private food', 'servingId' => $food['servingId']]]], $other);
        $this->assertResponseStatusCodeSame(404);
    }

    /// <summary>Verifies local search excludes foreign personal foods while retaining public and owned foods.</summary>
    public function testFoodSearchIsScopedToOwner(): void
    {
        $this->client->jsonRequest('POST', '/v1/foods/custom', ['name' => 'Privacy needle', 'calories' => 120], $this->authHeaders('search-owner'));
        $this->assertResponseStatusCodeSame(201);
        $this->createFoodFixture('public-privacy', 'Privacy public');
        $repo = $this->em->getRepository(\App\Entity\Food::class);
        $owner = $this->em->getRepository(User::class)->findOneBy(['firebaseUid' => 'search-owner']);
        self::assertInstanceOf(FoodRepository::class, $repo);
        self::assertCount(2, $repo->searchByNameOrBrand('Privacy', 20, 0, $owner));
        self::assertCount(1, $repo->searchByNameOrBrand('Privacy'));
    }

    /// <summary>Verifies a goals-only update preserves profile details and reminders.</summary>
    public function testPartialPreferencesPreserveUnrelatedFields(): void
    {
        $headers = $this->authHeaders('preferences-owner');
        $profile = ['age' => 31, 'weight_kg' => 78, 'workout_reminders' => ['enabled' => true]];
        $this->client->jsonRequest('PUT', '/v1/user/preferences', $profile, $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/v1/user/preferences', ['calorie_goal' => 2300], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        $saved = $this->jsonResponse();
        self::assertSame(31, $saved['age']);
        self::assertSame(78, $saved['weight_kg']);
        self::assertTrue($saved['workout_reminders']['enabled']);
        self::assertSame(2300, $saved['calorie_goal']);
    }

    /// <summary>Verifies invalid preference payloads cannot overwrite previously saved profile data.</summary>
    public function testInvalidPreferencesAreRejected(): void
    {
        $headers = $this->authHeaders('invalid-preferences');
        $this->client->jsonRequest('PUT', '/v1/user/preferences', ['age' => 31], $headers);
        foreach (['null', '[]', '{', '{"calorie_goal":-10}', '{"protein_goal":"invalid"}'] as $body) {
            $this->client->request('PUT', '/v1/user/preferences', [], [], $headers + ['CONTENT_TYPE' => 'application/json'], $body);
            $this->assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        self::assertSame(31, $this->jsonResponse()['age']);
    }
}
