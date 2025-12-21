<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\Auth\RegisterDTO;
use App\Domain\Entities\User;
use App\Domain\Enums\UserRole;
use App\Domain\Exceptions\DomainException;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\Password;
use App\Presentation\Exceptions\HttpException;

readonly class RegisterUseCase implements RegisterUseCaseInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(RegisterDTO $dto): array
    {
        // 1. Create Value Objects (Domain Validation)
        try {
            $email = new Email($dto->email);
            $password = new Password($dto->password);
        } catch (DomainException $e) {
            // Convert DomainException to HttpException (422 Unprocessable Entity) or similar
            // Here we use 400 Bad Request for simplicity or 422
            throw new HttpException($e->getMessage(), 422);
        }

        // 2. Check Business Rules (Uniqueness)
        if ($this->userRepository->findByEmail($email->value)) {
             throw new HttpException('Email already exists', 409);
        }

        // 3. Create Entity
        $user = new User(
            id: null,
            name: $dto->name,
            email: $email->value,
            password: password_hash($password->value, PASSWORD_DEFAULT),
            role: UserRole::USER
        );
        
        $this->userRepository->save($user);
        
        return ['message' => 'User created'];
    }
}
