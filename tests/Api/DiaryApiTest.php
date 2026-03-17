<?php

namespace App\Tests\Api;

final class DiaryApiTest extends ApiTestCase
{
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
}
