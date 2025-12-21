<?php

declare(strict_types=1);

namespace App\Application\DTOs\Auth;

use App\Application\DTOs\DTOInterface;

readonly class RegisterDTO implements DTOInterface
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password
        ];
    }
}
