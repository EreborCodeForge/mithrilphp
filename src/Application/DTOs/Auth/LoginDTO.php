<?php

declare(strict_types=1);

namespace App\Application\DTOs\Auth;

use App\Application\DTOs\DTOInterface;

readonly class LoginDTO implements DTOInterface
{
    public function __construct(
        public string $email,
        public string $password
    ) {}

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password
        ];
    }
}
