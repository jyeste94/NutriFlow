<?php

namespace App\Tests\Api;

final class ErrorLogApiTest extends ApiTestCase
{
    public function testErrorLogListSupportsSourceFilterAndDetail(): void
    {
        $one = $this->createErrorLogFixture('One', ['source' => 'kernel_exception', 'status_code' => 500]);
        $this->createErrorLogFixture('Two', ['source' => 'shutdown_handler', 'status_code' => 500]);
        $oneId = $one->getId()?->toRfc4122();
        $this->assertNotNull($oneId);

        $headers = $this->authHeaders('error-user-1');

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
}
