<?php

namespace App\Tests\Domain;

use App\Entity\ErrorLog;
use App\EventListener\ExceptionListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ExceptionListenerTest extends TestCase
{
    public function testRequestSecretsAndExceptionTextAreNotPersistedOrReturned(): void
    {
        $request = Request::create('/v1/private-path-secret?Access_Token=query-secret&nested[ApiKey]=nested-query-secret', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer authorization-secret',
            'HTTP_COOKIE' => 'session=cookie-secret',
            'HTTP_REFERER' => 'https://example.test/?token=referer-secret',
        ], json_encode([
            'account' => ['PassWord' => 'password-secret', 'children' => [['refreshToken' => 'refresh-secret']]],
            'customField' => 'arbitrary-secret',
        ]));
        $request->attributes->set('_route', 'api_example');
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('SQL exception-secret'));
        $storedLog = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $log) use (&$storedLog): void {
            $storedLog = $log;
        });

        (new ExceptionListener($em))->onKernelException($event);

        self::assertInstanceOf(ErrorLog::class, $storedLog);
        $context = $storedLog->getContext();
        self::assertSame('[REDACTED]', $context['body']['account']['PassWord']);
        self::assertSame('[REDACTED]', $context['body']['account']['children'][0]['refreshToken']);
        self::assertSame('[REDACTED]', $context['query']['nested']['ApiKey']);
        self::assertSame('api_example', $context['route']);
        self::assertSame(500, $context['status_code']);
        $persisted = json_encode([$storedLog->getMessage(), $storedLog->getStackTrace(), $context]);
        foreach (['private-path-secret', 'query-secret', 'nested-query-secret', 'authorization-secret', 'cookie-secret', 'referer-secret', 'password-secret', 'refresh-secret', 'arbitrary-secret', 'exception-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $persisted);
        }
        self::assertSame(500, $event->getResponse()->getStatusCode());
        self::assertStringNotContainsString('exception-secret', $event->getResponse()->getContent());
    }

    #[DataProvider('unstructuredBodies')]
    public function testUnstructuredAndFormBodiesDoNotLeak(string $contentType, string $body, array $parameters): void
    {
        $request = Request::create('/v1/import/hevy', 'POST', $parameters, [], [], ['CONTENT_TYPE' => $contentType], $body);
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Failure'));
        $storedLog = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $log) use (&$storedLog): void {
            $storedLog = $log;
        });

        (new ExceptionListener($em))->onKernelException($event);

        self::assertInstanceOf(ErrorLog::class, $storedLog);
        self::assertStringNotContainsString('body-secret', json_encode($storedLog->getContext()));
        self::assertSame(500, $event->getResponse()->getStatusCode());
    }

    public static function unstructuredBodies(): array
    {
        return [
            'plain text' => ['text/plain', 'body-secret', []],
            'malformed JSON' => ['application/json', '{"token":"body-secret"', []],
            'form' => ['application/x-www-form-urlencoded', 'password=body-secret', ['password' => 'body-secret']],
            'multipart fields' => ['multipart/form-data', '', ['nested' => ['PASSWORD' => 'body-secret']]],
        ];
    }

    public function testLoggingFailureStillProducesSafeApiResponse(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('database-secret'));
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/v1/import/hevy'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('application-secret'));

        (new ExceptionListener($em))->onKernelException($event);

        self::assertSame(500, $event->getResponse()->getStatusCode());
        self::assertStringNotContainsString('database-secret', $event->getResponse()->getContent());
        self::assertStringNotContainsString('application-secret', $event->getResponse()->getContent());
    }
}
