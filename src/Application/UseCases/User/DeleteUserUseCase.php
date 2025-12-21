<?php

declare(strict_types=1);

namespace App\Application\UseCases\User;

use App\Domain\Repositories\UserRepositoryInterface;
use App\Presentation\Exceptions\HttpException;

class DeleteUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(int $id): void
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw new HttpException('User not found', 404);
        }

        $this->userRepository->delete($id);
    }
}
