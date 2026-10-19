<?php

namespace App\EventListener;

use App\Entity\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionListener
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                try {
                    $log = new ErrorLog();
                    // PHP messages can contain SQL parameters, credentials or submitted data.
                    $log->setMessage('Fatal PHP error');
                    $log->setContext([
                        'type' => $error['type'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'source' => 'shutdown_handler',
                    ]);
                    $this->entityManager->persist($log);
                    $this->entityManager->flush();
                } catch (\Throwable) {
                    // Logging must not trigger another exception.
                }
            }
        });
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        if ($statusCode === 404 && $request->getPathInfo() === '/favicon.ico') {
            return;
        }

        $content = $request->getContent();
        $body = $content === '' ? null : '[REDACTED]';
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $body = $this->redactRequestData($decoded);
            } elseif ($request->request->count() > 0) {
                $body = $this->redactRequestData($request->request->all());
            }
        } elseif ($request->request->count() > 0) {
            $body = $this->redactRequestData($request->request->all());
        }

        $errorLog = new ErrorLog();
        // Exception messages and trace arguments are untrusted: do not persist them.
        $errorLog->setMessage($exception::class);
        $errorLog->setStackTrace(implode("\n", array_map(
            static fn (array $frame): string => sprintf(
                '%s:%s %s%s%s',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? ''
            ),
            $exception->getTrace()
        )));
        $errorLog->setContext([
            // A route name is server-defined; raw paths, hosts and query strings may contain secrets.
            'route' => $request->attributes->get('_route'),
            'method' => $request->getMethod(),
            'headers' => $this->redactRequestData($request->headers->all()),
            'query' => $this->redactRequestData($request->query->all()),
            'body' => $body,
            'status_code' => $statusCode,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'source' => 'kernel_exception',
        ]);

        try {
            $this->entityManager->persist($errorLog);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // A database failure must not prevent the safe public response.
        }

        if ($statusCode >= 500 && str_starts_with($request->getPathInfo(), '/v1/')) {
            $event->setResponse(new JsonResponse([
                'error' => 'Ha ocurrido un error interno. Inténtalo de nuevo más tarde.',
            ], $statusCode));
        }
    }

    private function redactRequestData(array $data, int $depth = 0): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            $sensitive = preg_match('/password|passwd|pwd|token|secret|apikey|authorization|cookie|credential|session|privatekey|clientsecret/', $normalized) === 1;
            // Keep structure, never submitted values: credentials can use arbitrary field names.
            $redacted[$key] = !$sensitive && is_array($value) && $depth < 16
                ? $this->redactRequestData($value, $depth + 1)
                : '[REDACTED]';
        }

        return $redacted;
    }
}
