<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\DomainException;

class Password
{
    public readonly string $value;

    public function __construct(string $value)
    {
        if (strlen($value) < 6) {
            throw new DomainException("Password must be at least 6 characters long");
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
