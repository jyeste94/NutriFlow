<?php

namespace App\Tests\Api;

final class RoutineApiTest extends ApiTestCase
{
    public function testCreateRoutineRejectsInvalidDaysOfWeek(): void
    {
        $this->client->jsonRequest(
            'POST',
            '/v1/routines',
            ['name' => 'My routine', 'daysOfWeek' => [1, 8]],
            $this->authHeaders('routine-user-1')
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testDeleteRoutineIsForbiddenForOtherUser(): void
    {
        $ownerHeaders = $this->authHeaders('routine-owner-1');
        $otherHeaders = $this->authHeaders('routine-other-1');

        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'Owner routine'], $ownerHeaders);
        $this->assertResponseStatusCodeSame(201);
        $createData = $this->jsonResponse();
        $routineId = (string) ($createData['id'] ?? '');
        $this->assertNotSame('', $routineId);

        $this->client->request('DELETE', '/v1/routines/' . $routineId, [], [], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testRoutinesPaginationIncludesHeaders(): void
    {
        $headers = $this->authHeaders('routine-paged-user');

        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'A'], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'B'], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'C'], $headers);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/routines?page=2&limit=1', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        $this->assertSame('3', $response->headers->get('X-Total-Count'));
        $this->assertSame('2', $response->headers->get('X-Page'));
        $this->assertSame('1', $response->headers->get('X-Per-Page'));

        $data = $this->jsonResponse();
        $this->assertCount(1, $data);
    }
}
