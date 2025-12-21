<?php

declare(strict_types=1);

namespace App\Application\DTOs\User;

use App\Application\DTOs\DTOInterface;
use App\Domain\Enums\UserRole;

readonly class CreateUserDTO implements DTOInterface
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?UserRole $role = null
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'role' => $this->role?->value
        ];
    }
}
