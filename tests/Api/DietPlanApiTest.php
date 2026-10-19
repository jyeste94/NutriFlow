<?php

namespace App\Tests\Api;

final class DietPlanApiTest extends ApiTestCase
{
    public function testDietPlanCrudAndApplyFlow(): void
    {
        $serving = $this->createServingFixture('diet-food-1');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diet-user-1');
        $payload = [
            'name' => 'Weekly cut',
            'description' => 'Cutting phase',
            'supplement_protocol' => 'Creatine',
            'is_default' => true,
            'days' => [
                [
                    'day_of_week' => 'mon',
                    'meals' => [
                        [
                            'serving_id' => $servingId,
                            'meal_type' => 'breakfast',
                            'multiplier' => 1.5,
                            'option_group' => 'A',
                            'notes' => 'Before training',
                        ],
                    ],
                ],
                [
                    'day_of_week' => 'sun',
                    'meals' => [
                        ['serving_id' => $servingId, 'meal_type' => 'dinner', 'multiplier' => 0.5],
                        ['serving_id' => $servingId, 'meal_type' => 'snack', 'multiplier' => 1],
                    ],
                ],
            ],
        ];

        $this->client->jsonRequest('POST', '/v1/diet-plans', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $planId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $planId);

        $this->client->request('GET', '/v1/diet-plans/' . $planId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $plan = $this->jsonResponse();
        $this->assertSame('Weekly cut', $plan['name']);
        $this->assertCount(2, $plan['days']);
        $this->assertCount(1, $plan['days'][0]['meals']);
        $this->assertCount(2, $plan['days'][1]['meals']);
        $this->assertEquals(1.5, $plan['days'][0]['meals'][0]['multiplier']);
        $this->client->request('GET', '/v1/diet-plans', [], [], $headers);
        $this->assertSame(2, $this->jsonResponse()[0]['day_count']);

        $this->client->jsonRequest(
            'PUT',
            '/v1/diet-plans/' . $planId,
            [
                'name' => 'Weekly cut updated',
                'is_default' => true,
                'days' => [
                    [
                        'day_of_week' => 'tue',
                        'meals' => [
                            [
                                'serving_id' => $servingId,
                                'meal_type' => 'lunch',
                                'multiplier' => 2,
                            ],
                        ],
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/diet-plans/' . $planId, [], [], $headers);
        $updated = $this->jsonResponse();
        $this->assertSame('Weekly cut updated', $updated['name']);
        $this->assertTrue($updated['is_default']);
        $this->assertCount(1, $updated['days']);
        $this->assertSame('tue', $updated['days'][0]['day_of_week']);
        $this->assertEquals(2, $updated['days'][0]['meals'][0]['multiplier']);

        $this->client->jsonRequest('POST', '/v1/diet-plans/' . $planId . '/apply', ['start_date' => '2026-03-18'], $headers);
        $this->assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/v1/diaries/2026-03-17', [], [], $headers);
        $this->assertCount(0, $this->jsonResponse()['entries']);

        $this->client->jsonRequest(
            'POST',
            '/v1/diet-plans/' . $planId . '/apply',
            ['start_date' => '2026-03-18', 'confirm_consumed' => true],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $apply = $this->jsonResponse();
        $this->assertCount(1, $apply['entry_ids']);

        $this->client->request('GET', '/v1/diaries/2026-03-17', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $diary = $this->jsonResponse();
        $this->assertCount(1, $diary['entries']);
        $this->assertSame('lunch', $diary['entries'][0]['mealType']);
        $this->assertEquals(2, $diary['entries'][0]['multiplier']);

        $this->client->request('DELETE', '/v1/diet-plans/' . $planId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/diet-plans/' . $planId, [], [], $headers);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/v1/diaries/2026-03-17', [], [], $headers);
        $this->assertCount(1, $this->jsonResponse()['entries']);
    }

    public function testDietPlanValidationAndDefaultUniqueness(): void
    {
        $serving = $this->createServingFixture('diet-food-2');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diet-user-2');

        $this->client->jsonRequest(
            'POST',
            '/v1/diet-plans',
            [
                'name' => 'Invalid plan',
                'days' => [
                    ['day_of_week' => 'noday', 'meals' => []],
                ],
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(400);

        $this->client->jsonRequest(
            'POST',
            '/v1/diet-plans',
            [
                'name' => 'Plan A',
                'is_default' => true,
                'days' => [
                    [
                        'day_of_week' => 'mon',
                        'meals' => [
                            [
                                'serving_id' => $servingId,
                                'meal_type' => 'breakfast',
                                'multiplier' => 1,
                            ],
                        ],
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $planAId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $planAId);

        $this->client->jsonRequest(
            'POST',
            '/v1/diet-plans',
            [
                'name' => 'Plan B',
                'is_default' => true,
                'days' => [],
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/diet-plans', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $plans = $this->jsonResponse();
        $defaultPlans = array_values(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['is_default'] ?? false)));
        $this->assertCount(1, $defaultPlans);
        $this->assertSame('Plan B', $defaultPlans[0]['name']);
    }

    public function testRejectedUpdateDoesNotChangeTheDefaultPlan(): void
    {
        $headers = $this->authHeaders('diet-atomic-user');
        $this->client->jsonRequest('POST', '/v1/diet-plans', ['name' => 'Original default', 'is_default' => true], $headers);
        $this->assertResponseStatusCodeSame(201);
        $defaultId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('POST', '/v1/diet-plans', ['name' => 'Other plan'], $headers);
        $this->assertResponseStatusCodeSame(201);
        $otherId = $this->jsonResponse()['id'];

        $this->client->jsonRequest('PUT', '/v1/diet-plans/' . $otherId, [
            'name' => 'Must not persist',
            'is_default' => true,
            'days' => [['day_of_week' => 'invalid']],
        ], $headers);
        $this->assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/v1/diet-plans/' . $defaultId, [], [], $headers);
        $this->assertTrue($this->jsonResponse()['is_default']);
        $this->client->request('GET', '/v1/diet-plans/' . $otherId, [], [], $headers);
        $other = $this->jsonResponse();
        $this->assertFalse($other['is_default']);
        $this->assertSame('Other plan', $other['name']);
    }

    public function testDietPlanDeleteIsForbiddenForOtherUser(): void
    {
        $serving = $this->createServingFixture('diet-food-3');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $ownerHeaders = $this->authHeaders('diet-owner-1');
        $this->client->jsonRequest(
            'POST',
            '/v1/diet-plans',
            [
                'name' => 'Owner plan',
                'days' => [
                    [
                        'day_of_week' => 'mon',
                        'meals' => [
                            [
                                'serving_id' => $servingId,
                                'meal_type' => 'breakfast',
                                'multiplier' => 1,
                            ],
                        ],
                    ],
                ],
            ],
            $ownerHeaders
        );
        $this->assertResponseStatusCodeSame(201);
        $planId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $planId);

        $this->client->request('DELETE', '/v1/diet-plans/' . $planId, [], [], $this->authHeaders('diet-other-1'));
        $this->assertResponseStatusCodeSame(403);
    }
}
