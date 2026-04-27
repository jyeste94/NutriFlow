<?php

namespace App\EventListener;

use App\Entity\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionListener
{
    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'x-api-key', 'php-auth-pw'];

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        // Registrar shutdown handler para capturar warnings/notices fatales
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
                try {
                    $log = new ErrorLog();
                    $log->setMessage($error['message']);
                    $log->setContext([
                        'type' => $error['type'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'source' => 'shutdown_handler',
                    ]);
                    $this->entityManager->persist($log);
                    $this->entityManager->flush();
                } catch (\Exception) {
                    // No entrar en bucle infinito
                }
            }
        });
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // No loggear 404 de favicon
        $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
        if ($statusCode === 404 && str_contains($request->getUri(), 'favicon')) {
            return;
        }

        $headers = $request->headers->all();
        foreach ($headers as $key => &$value) {
            if (in_array(strtolower($key), self::SENSITIVE_HEADERS, true)) {
                $value = ['[REDACTED]'];
            }
        }
        unset($value);

        $body = $request->getContent();
        if ($body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach (['password', 'token', 'secret', 'api_key', 'firebase_token'] as $field) {
                    if (isset($decoded[$field])) {
                        $decoded[$field] = '[REDACTED]';
                    }
                }
                $body = json_encode($decoded);
            }
        }

        $errorLog = new ErrorLog();
        $errorLog->setMessage($exception->getMessage());
        $errorLog->setStackTrace($exception->getTraceAsString());
        $errorLog->setContext([
            'uri' => $request->getUri(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'headers' => $headers,
            'query' => $request->query->all(),
            'body' => $body,
            'status_code' => $statusCode,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'source' => 'kernel_exception',
        ]);

        try {
            $this->entityManager->persist($errorLog);
            $this->entityManager->flush();
        } catch (\Exception) {
            // No entrar en bucle infinito si falla la DB
        }
    }
}
