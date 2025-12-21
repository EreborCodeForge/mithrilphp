<?php

declare(strict_types=1);

namespace App\Application\UseCases\User;

use App\Domain\Repositories\UserRepositoryInterface;

class ListUsersUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(): array
    {
        $users = $this->userRepository->findAll();
        return array_map(fn($user) => $user->toArray(), $users);
    }
}
