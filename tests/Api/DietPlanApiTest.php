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
        $this->assertCount(1, $plan['days']);
        $this->assertCount(1, $plan['days'][0]['meals']);

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

        $this->client->jsonRequest(
            'POST',
            '/v1/diet-plans/' . $planId . '/apply',
            ['start_date' => '2026-03-16'],
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
