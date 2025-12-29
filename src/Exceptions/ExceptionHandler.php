<?php

declare(strict_types=1);

namespace Erebor\Mithril\Exceptions;

use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Logger\LoggerInterface;
use Erebor\Mithril\Presentation\Exceptions\HttpException;
use Erebor\Mithril\Presentation\Exceptions\ValidationException;
use Throwable;

class ExceptionHandler
{
    private LoggerInterface $logger;
    private bool $debug;

    public function __construct(LoggerInterface $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public function handle(Throwable $exception): void
    {
        $this->logException($exception);
        $this->renderResponse($exception);
    }

    private function logException(Throwable $e): void
    {
        $context = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        if ($e instanceof ValidationException) {
            $context['errors'] = $e->getErrors();
            $this->logger->warning($e->getMessage(), $context);
        } elseif ($e instanceof HttpException && $e->getCode() < 500) {
             $this->logger->warning($e->getMessage(), $context);
        } else {
            $this->logger->error($e->getMessage(), $context);
        }
    }

    private function renderResponse(Throwable $e): void
    {
        $statusCode = 500;
        $body = ['error' => 'Internal Server Error'];

        if ($e instanceof HttpException) {
            $statusCode = $e->getCode();
            $body = ['error' => $e->getMessage()];
        } elseif ($e instanceof ValidationException) {
            $statusCode = $e->getCode();
            $body = [
                'error' => $e->getMessage(),
                'details' => $e->getErrors()
            ];
        }

        if ($this->debug && $statusCode >= 500) {
            $body['debug'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode(PHP_EOL, $e->getTraceAsString())
            ];
        }

        (new Response())->json($body, $statusCode)->send();
    }
}
