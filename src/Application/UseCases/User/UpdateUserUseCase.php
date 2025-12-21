<?php

declare(strict_types=1);

namespace App\Application\UseCases\User;

use App\Application\DTOs\User\UpdateUserDTO;
use App\Domain\Exceptions\DomainException;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\Password;
use App\Presentation\Exceptions\HttpException;

class UpdateUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(int $id, UpdateUserDTO $dto): array
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw new HttpException('User not found', 404);
        }

        if ($dto->name) {
            $user->name = $dto->name;
        }

        if ($dto->email) {
            try {
                $email = new Email($dto->email);
            } catch (DomainException $e) {
                throw new HttpException($e->getMessage(), 422);
            }

            if ($user->email !== $email->value && $this->userRepository->findByEmail($email->value)) {
                throw new HttpException('Email already exists', 409);
            }
            $user->email = $email->value;
        }

        if ($dto->password) {
            try {
                $password = new Password($dto->password);
                $user->password = password_hash($password->value, PASSWORD_DEFAULT);
            } catch (DomainException $e) {
                throw new HttpException($e->getMessage(), 422);
            }
        }

        if ($dto->role) {
            $user->role = $dto->role;
        }

        $this->userRepository->update($user);

        return $user->toArray();
    }
}
