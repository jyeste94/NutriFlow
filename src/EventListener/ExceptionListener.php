<?php

namespace App\EventListener;

use App\Entity\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionListener
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Evitar loggear errores 404 de rutas inexistentes (favicon, etc.) para no llenar la DB de basura
        // Si quieres loggear TODO, quita esta condición.
        $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
        if ($statusCode === 404 && str_contains($request->getUri(), 'favicon')) {
            return;
        }

        $errorLog = new ErrorLog();
        $errorLog->setMessage($exception->getMessage());
        $errorLog->setStackTrace($exception->getTraceAsString());
        
        $errorLog->setContext([
            'uri' => $request->getUri(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'headers' => $request->headers->all(),
            'query' => $request->query->all(),
            'body' => $request->getContent(),
            'status_code' => $statusCode,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        try {
            $this->entityManager->persist($errorLog);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Si falla el guardado en DB, no queremos entrar en un bucle infinito de excepciones
        }
    }
}
