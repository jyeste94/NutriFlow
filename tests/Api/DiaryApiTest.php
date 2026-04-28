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
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/diaries/2026-03-13', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertCount(0, $data['entries']);
        $this->assertSame(0, $data['totalCalories']);
    }
}
