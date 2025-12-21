<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\UserRole;

class User
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $email,
        public string $password,
        public UserRole $role = UserRole::USER
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value
        ];
    }
}
