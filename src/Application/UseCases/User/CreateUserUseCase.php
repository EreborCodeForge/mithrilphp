<?php

declare(strict_types=1);

namespace App\Application\UseCases\User;

use App\Application\DTOs\User\CreateUserDTO;
use App\Domain\Entities\User;
use App\Domain\Enums\UserRole;
use App\Domain\Exceptions\DomainException;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\Password;
use App\Presentation\Exceptions\HttpException;

class CreateUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(CreateUserDTO $dto): array
    {
        try {
            $email = new Email($dto->email);
            $password = new Password($dto->password);
        } catch (DomainException $e) {
            throw new HttpException($e->getMessage(), 422);
        }

        if ($this->userRepository->findByEmail($email->value)) {
            throw new HttpException('Email already exists', 409);
        }

        $user = new User(
            id: null,
            name: $dto->name,
            email: $email->value,
            password: password_hash($password->value, PASSWORD_DEFAULT),
            role: $dto->role ?? UserRole::USER
        );

        $savedUser = $this->userRepository->save($user);

        return $savedUser->toArray();
    }
}
