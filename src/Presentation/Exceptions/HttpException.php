<?php

declare(strict_types=1);

namespace App\Presentation\Exceptions;

use App\Core\Exceptions\AppException;
use Throwable;

class HttpException extends AppException
{
    public function __construct(string $message, int $statusCode = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }
}
