<?php

namespace App\Tests\Api;

final class AuthAndAccessTest extends ApiTestCase
{
    public function testPreflightRequestDoesNotRequireAuthentication(): void
    {
        $this->client->request(
            'OPTIONS',
            '/v1/routines',
            [],
            [],
            [
                'HTTP_ORIGIN' => 'http://localhost:3000',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,x-api-key',
            ]
        );

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 204]);
    }

    public function testRoutinesEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/v1/routines');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRoutinesEndpointAllowsAuthenticatedAccess(): void
    {
        $this->client->request('GET', '/v1/routines', [], [], $this->authHeaders('user-auth-1'));

        $this->assertResponseIsSuccessful();
        $this->assertJson((string) $this->client->getResponse()->getContent());
    }
}
