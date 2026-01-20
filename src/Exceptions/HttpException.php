<?php

declare(strict_types=1);

namespace Erebor\Mithril\Exceptions;

use Throwable;

class HttpException extends \RuntimeException
{
    private int $statusCode;

    private array $headers;

    public function __construct(
        int $statusCode,
        string $message = 'HTTP Error',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
