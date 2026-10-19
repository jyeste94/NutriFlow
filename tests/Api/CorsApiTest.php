<?php

namespace App\Tests\Api;

final class CorsApiTest extends ApiTestCase
{
    private ?string $originalServerOrigin;
    private ?string $originalEnvOrigin;

    protected function setUp(): void
    {
        $this->originalServerOrigin = $_SERVER['CORS_ALLOW_ORIGIN'] ?? null;
        $this->originalEnvOrigin = $_ENV['CORS_ALLOW_ORIGIN'] ?? null;
        $_SERVER['CORS_ALLOW_ORIGIN'] = $_ENV['CORS_ALLOW_ORIGIN'] = '^https://allowed\.example$';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->originalServerOrigin === null) {
                unset($_SERVER['CORS_ALLOW_ORIGIN']);
            } else {
                $_SERVER['CORS_ALLOW_ORIGIN'] = $this->originalServerOrigin;
            }
            if ($this->originalEnvOrigin === null) {
                unset($_ENV['CORS_ALLOW_ORIGIN']);
            } else {
                $_ENV['CORS_ALLOW_ORIGIN'] = $this->originalEnvOrigin;
            }
        }
    }

    public function testAllowedOriginSupportsPreflightAndPaginationHeaders(): void
    {
        $this->client->request('OPTIONS', '/v1/exercises', [], [], [
            'HTTP_ORIGIN' => 'https://allowed.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('https://allowed.example', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('PUT', $this->client->getResponse()->headers->get('Access-Control-Allow-Methods'));

        $this->client->request('GET', '/v1/exercises', [], [], $this->authHeaders('cors-reader') + ['HTTP_ORIGIN' => 'https://allowed.example']);
        $this->assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        $this->assertSame('https://allowed.example', $headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('x-total-count', strtolower($headers->get('Access-Control-Expose-Headers')));
    }

    public function testDisallowedOriginIsNeverReopenedByResponseListener(): void
    {
        $this->client->request('OPTIONS', '/v1/exercises', [], [], [
            'HTTP_ORIGIN' => 'https://untrusted.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
        ]);
        $this->assertFalse($this->client->getResponse()->headers->has('Access-Control-Allow-Origin'));

        $this->client->request('GET', '/v1/exercises', [], [], $this->authHeaders('cors-reader') + ['HTTP_ORIGIN' => 'https://untrusted.example']);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }
}
