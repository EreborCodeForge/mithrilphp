<?php

declare(strict_types=1);

namespace App\Presentation\Exceptions;

use App\Core\Exceptions\AppException;

class ValidationException extends AppException
{
    private array $errors;

    public function __construct(array $errors, string $message = "Validation Failed", int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
