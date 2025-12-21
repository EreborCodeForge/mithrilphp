<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Domain\Entities\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function findById(int $id): ?User;
    public function findAll(): array;
    public function save(User $user): User;
    public function update(User $user): bool;
    public function delete(int $id): bool;
}
