<?php

namespace App\Tests\Api;

final class DiaryApiTest extends ApiTestCase
{
    public function testGetDiaryReturnsCreatedEntriesAndTotals(): void
    {
        $serving = $this->createServingFixture();
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-2');

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-12/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'lunch',
                'multiplier' => 2,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/diaries/2026-03-12', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $data = $this->jsonResponse();
        $this->assertEquals(200.0, $data['totalCalories']);
        $this->assertCount(1, $data['entries']);
        $this->assertSame('lunch', $data['entries'][0]['mealType']);
    }

    public function testAddEntryRejectsInvalidMealType(): void
    {
        $serving = $this->createServingFixture();
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-10/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'brunch',
                'multiplier' => 1.5,
            ],
            $this->authHeaders('diary-user-1')
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testDeleteEntryIsForbiddenForOtherUser(): void
    {
        $serving = $this->createServingFixture();
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $ownerHeaders = $this->authHeaders('diary-owner-1');
        $otherHeaders = $this->authHeaders('diary-other-1');

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-11/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'lunch',
                'multiplier' => 1,
            ],
            $ownerHeaders
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');
        $this->assertNotSame('', $entryId);

        $this->client->request('DELETE', '/v1/diaries/entries/' . $entryId, [], [], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteEntryRemovesItFromDiary(): void
    {
        $serving = $this->createServingFixture('food-ext-delete');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-3');
        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-13/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'dinner',
                'multiplier' => 1,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');
        $this->assertNotSame('', $entryId);

        $this->client->request('DELETE', '/v1/diaries/entries/' . $entryId, [], [], $headers);
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/v1/diaries/2026-03-13', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertCount(0, $data['entries']);
        $this->assertSame(0, $data['totalCalories']);
    }

    public function testDeleteEntryIsIdempotentWhenAlreadyDeleted(): void
    {
        $headers = $this->authHeaders('diary-user-idempotent');
        // Random non-existent UUID
        $fakeUuid = '00000000-0000-0000-0000-000000000001';

        $this->client->request('DELETE', '/v1/diaries/entries/' . $fakeUuid, [], [], $headers);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetDiaryReturnsComputedEntryMacrosAndAmounts(): void
    {
        $serving = $this->createServingFixture('food-ext-macros');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-macros');
        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-14/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'breakfast',
                'multiplier' => 2.5,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/diaries/2026-03-14', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertCount(1, $data['entries']);
        $entry = $data['entries'][0];
        $this->assertSame('breakfast', $entry['mealType']);
        $this->assertEquals(2.5, $entry['multiplier']);
        $this->assertEquals(250.0, $entry['calories']);
        $this->assertArrayHasKey('proteins', $entry);
        $this->assertArrayHasKey('carbs', $entry);
        $this->assertArrayHasKey('fats', $entry);
    }

    public function testUpdateEntryChangesMultiplierAndMealType(): void
    {
        $serving = $this->createServingFixture('food-ext-update');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-update');
        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-15/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'breakfast',
                'multiplier' => 1.0,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');
        $this->assertNotSame('', $entryId);

        // Update multiplier to 3.0 and move to lunch
        $this->client->jsonRequest(
            'PUT',
            '/v1/diaries/entries/' . $entryId,
            [
                'mealType' => 'lunch',
                'multiplier' => 3.0,
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertSame('lunch', $data['entry']['mealType']);
        $this->assertEquals(3.0, $data['entry']['multiplier']);
        $this->assertEquals(300.0, $data['entry']['calories']);
        $this->assertEquals(300.0, $data['diary']['totalCalories']);

        // Verify with subsequent GET
        $this->client->request('GET', '/v1/diaries/2026-03-15', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $getData = $this->jsonResponse();
        $this->assertEquals(300.0, $getData['totalCalories']);
        $this->assertSame('lunch', $getData['entries'][0]['mealType']);
    }

    public function testUpdateEntryIsForbiddenForOtherUser(): void
    {
        $serving = $this->createServingFixture('food-ext-update-forbid');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $ownerHeaders = $this->authHeaders('diary-owner-update');
        $otherHeaders = $this->authHeaders('diary-other-update');

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-16/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'dinner',
                'multiplier' => 1.0,
            ],
            $ownerHeaders
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');

        $this->client->jsonRequest(
            'PUT',
            '/v1/diaries/entries/' . $entryId,
            [
                'multiplier' => 2.0,
            ],
            $otherHeaders
        );
        $this->assertResponseStatusCodeSame(403);
    }
    public function testClientIdentityRetriesOnceButPreservesSeparateIdenticalConsumptions(): void
    {
        $serving = $this->createServingFixture('diary-idempotency-serving');
        $serving->setAmount(150);
        $serving->setUnit('g');
        $serving->setCalories(300);
        $this->em->flush();
        $headers = $this->authHeaders('diary-idempotency');
        $payload = [
            'client_id' => '12ae161b-95a8-4ce1-aeb7-d37949ea7814',
            'serving_id' => $serving->getId()?->toRfc4122(), 'mealType' => 'breakfast',
            'amountGrams' => 150, 'multiplier' => 1.5,
        ];
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/diaries/2026-10-19', [], [], $headers);
        $diary = $this->jsonResponse();
        $this->assertCount(1, $diary['entries']);
        $this->assertEquals(150, $diary['entries'][0]['amount']);
        $this->assertEquals(300, $diary['totalCalories']);
        $payload['client_id'] = 'e843f14f-18d4-40ac-9adb-24e01b1c86c9';
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/v1/diaries/2026-10-19', [], [], $headers);
        $this->assertCount(2, $this->jsonResponse()['entries']);
        $this->assertEquals(600, $this->jsonResponse()['totalCalories']);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $this->authHeaders('other-diary-user'));
        $this->assertResponseStatusCodeSame(409);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(409);
    }

}

