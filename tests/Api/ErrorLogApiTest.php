<?php

namespace App\Tests\Api;

final class ErrorLogApiTest extends ApiTestCase
{
    /// <summary>Verifies administrators can filter and inspect internal error logs.</summary>
    public function testErrorLogListSupportsSourceFilterAndDetail(): void
    {
        $one = $this->createErrorLogFixture('One', ['source' => 'kernel_exception', 'status_code' => 500]);
        $this->createErrorLogFixture('Two', ['source' => 'shutdown_handler', 'status_code' => 500]);
        $oneId = $one->getId()?->toRfc4122();
        $this->assertNotNull($oneId);

        $headers = $this->authHeaders('error-user-1');
        $admin = new \App\Entity\User();
        $admin->setFirebaseUid('error-user-1');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->request('GET', '/v1/errors?source=kernel_exception', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame('One', $list[0]['message']);

        $this->client->request('GET', '/v1/errors/' . $oneId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();
        $this->assertSame('One', $detail['message']);
        $this->assertSame('kernel_exception', $detail['context']['source'] ?? null);
    }

    /// <summary>Verifies ordinary accounts cannot list or inspect internal error logs.</summary>
    public function testOrdinaryAccountCannotReadErrors(): void
    {
        $id = $this->createErrorLogFixture()->getId()->toRfc4122();
        $headers = $this->authHeaders('ordinary-error-user');
        $this->client->request('GET', '/v1/errors', [], [], $headers);
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/v1/errors/' . $id, [], [], $headers);
        $this->assertResponseStatusCodeSame(403);
    }
}
