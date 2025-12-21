<?php

declare(strict_types=1);

namespace App\Application\DTOs\User;

use App\Application\DTOs\DTOInterface;
use App\Domain\Enums\UserRole;

readonly class UpdateUserDTO implements DTOInterface
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $password = null,
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
