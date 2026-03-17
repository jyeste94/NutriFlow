<?php

namespace App\Tests\Api;

final class AuthAndAccessTest extends ApiTestCase
{
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
