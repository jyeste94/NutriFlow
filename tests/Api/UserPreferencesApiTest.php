<?php

namespace App\Tests\Api;

final class UserPreferencesApiTest extends ApiTestCase
{
    public function testMissingProfileDoesNotInventTargetsOrExpenditure(): void
    {
        $this->client->request('GET', '/v1/user/preferences', [], [], $this->authHeaders('energy-empty'));
        $this->assertResponseIsSuccessful();
        $prefs = $this->jsonResponse();
        foreach (['calorie_goal', 'protein_goal', 'carbs_goal', 'fat_goal', 'maintenance_calories', 'energy_goal'] as $field) {
            self::assertNull($prefs[$field]);
        }
    }

    public function testCalculatedGoalPersistsSeparatelyFromMaintenanceAndPreservesOtherPreferences(): void
    {
        $headers = $this->authHeaders('energy-goal');
        $this->client->jsonRequest('PUT', '/v1/user/preferences', [
            'gender' => 'male', 'age' => 30, 'weight_kg' => 75, 'height_cm' => 175,
            'activity' => 1.55, 'energy_goal' => 'lose', 'calorie_adjustment' => -300,
            'maintenance_calories' => 9999, 'calorie_goal' => 9999,
            'workout_reminders' => ['enabled' => true],
        ], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/v1/user/preferences', ['protein_goal' => 140], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        $prefs = $this->jsonResponse();
        self::assertSame(2633, $prefs['maintenance_calories']);
        self::assertSame(2333, $prefs['calorie_goal']);
        self::assertSame('lose', $prefs['energy_goal']);
        self::assertSame(-300, $prefs['calorie_adjustment']);
        self::assertTrue($prefs['workout_reminders']['enabled']);
        self::assertSame(140, $prefs['protein_goal']);

        $this->client->jsonRequest('PUT', '/v1/user/preferences', ['energy_goal' => 'maintain', 'calorie_adjustment' => 0], $headers);
        $this->assertResponseIsSuccessful();
        self::assertSame(2633, $this->jsonResponse()['calorie_goal']);
        $this->client->jsonRequest('PUT', '/v1/user/preferences', ['energy_goal' => 'gain', 'calorie_adjustment' => 200], $headers);
        $this->assertResponseIsSuccessful();
        self::assertSame(2833, $this->jsonResponse()['calorie_goal']);
    }

    public function testInvalidAdjustmentAndIncompleteProfileCannotOverwriteSavedPreferences(): void
    {
        $headers = $this->authHeaders('energy-validation');
        $this->client->jsonRequest('PUT', '/v1/user/preferences', [
            'gender' => 'female', 'age' => 30, 'weight_kg' => 65, 'height_cm' => 165,
            'activity' => 1.2, 'energy_goal' => 'maintain', 'calorie_adjustment' => 0,
        ], $headers);
        $this->assertResponseIsSuccessful();
        $saved = $this->jsonResponse();
        foreach ([
            ['energy_goal' => 'lose', 'calorie_adjustment' => 300],
            ['energy_goal' => 'gain', 'calorie_adjustment' => -200],
            ['calorie_adjustment' => 1],
            ['energy_goal' => 'lose', 'calorie_adjustment' => -1001],
            ['energy_goal' => 'lose', 'calorie_adjustment' => -0.5],
            ['age' => null], ['age' => 17], ['activity' => 2], ['gender' => 'unknown'],
            ['weight_kg' => true],
        ] as $invalid) {
            $this->client->jsonRequest('PUT', '/v1/user/preferences', $invalid, $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        $prefs = $this->jsonResponse();
        self::assertSame($saved['calorie_goal'], $prefs['calorie_goal']);
        self::assertSame('maintain', $prefs['energy_goal']);
        self::assertSame(30, $prefs['age']);
    }

    public function testManualTargetReplacesCalculatedGoalAndRolesCannotBeGrantedThroughPreferences(): void
    {
        $headers = $this->authHeaders('energy-manual');
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        $roles = $this->jsonResponse()['roles'];
        $this->client->jsonRequest('PUT', '/v1/user/preferences', [
            'gender' => 'male', 'age' => 30, 'weight_kg' => 75, 'height_cm' => 175,
            'activity' => 1.55, 'energy_goal' => 'lose', 'calorie_adjustment' => -300,
        ], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/v1/user/preferences', ['calorie_goal' => 2400, 'roles' => ['ROLE_ADMIN']], $headers);
        $this->assertResponseIsSuccessful();
        self::assertSame($roles, $this->jsonResponse()['roles']);
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        $prefs = $this->jsonResponse();
        self::assertSame(2400, $prefs['calorie_goal']);
        self::assertSame(2633, $prefs['maintenance_calories']);
        self::assertNull($prefs['energy_goal']);
        self::assertNull($prefs['calorie_adjustment']);
        self::assertSame($roles, $prefs['roles']);
    }
}
