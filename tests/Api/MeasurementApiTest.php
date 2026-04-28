<?php

namespace App\Tests\Api;

final class MeasurementApiTest extends ApiTestCase
{
    public function testMeasurementCrudFlow(): void
    {
        $headers = $this->authHeaders('measurement-user-1');

        $this->client->jsonRequest(
            'POST',
            '/v1/measurements',
            [
                'date' => '2026-03-14',
                'weight_kg' => 82.4,
                'body_fat_pct' => 15.8,
                'waist_cm' => 81.5,
                'notes' => 'Weekly check-in',
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $measurementId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $measurementId);

        $this->client->request('GET', '/v1/measurements', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame(82.4, $list[0]['weight_kg']);

        $this->client->jsonRequest(
            'PUT',
            '/v1/measurements/' . $measurementId,
            [
                'weight_kg' => 81.9,
                'body_fat_pct' => null,
                'waist_cm' => '',
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/measurements', [], [], $headers);
        $updated = $this->jsonResponse();
        $this->assertSame(81.9, $updated[0]['weight_kg']);
        $this->assertNull($updated[0]['body_fat_pct']);
        $this->assertNull($updated[0]['waist_cm']);

        $this->client->request('DELETE', '/v1/measurements/' . $measurementId, [], [], $headers);
        $this->assertResponseIsSuccessful();
    }

    public function testMeasurementCreateAndUpdateValidateInput(): void
    {
        $headers = $this->authHeaders('measurement-user-2');

        $this->client->jsonRequest('POST', '/v1/measurements', ['weight_kg' => -1], $headers);
        $this->assertResponseStatusCodeSame(400);

        $measurement = $this->createMeasurementFixture('measurement-user-2');
        $measurementId = $measurement->getId()?->toRfc4122();
        $this->assertNotNull($measurementId);

        $this->client->jsonRequest(
            'PUT',
            '/v1/measurements/' . $measurementId,
            ['date' => 'not-a-date'],
            $headers
        );
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMeasurementDeleteIsForbiddenForOtherUser(): void
    {
        $measurement = $this->createMeasurementFixture('measurement-owner-1');
        $measurementId = $measurement->getId()?->toRfc4122();
        $this->assertNotNull($measurementId);

        $this->client->request(
            'DELETE',
            '/v1/measurements/' . $measurementId,
            [],
            [],
            $this->authHeaders('measurement-other-1')
        );
        $this->assertResponseStatusCodeSame(403);
    }
}
