<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\Auth\LoginDTO;
use App\Domain\Exceptions\DomainException;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\ValueObjects\Email;
use App\Presentation\Exceptions\HttpException;

readonly class LoginUseCase implements LoginUseCaseInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(LoginDTO $dto): array
    {
        try {
            $email = new Email($dto->email);
        } catch (DomainException $e) {
            throw new HttpException($e->getMessage(), 422);
        }

        $user = $this->userRepository->findByEmail($email->value);

        if (!$user || !password_verify($dto->password, $user->password)) {
            throw new HttpException('Invalid credentials', 401);
        }

        // Ideally, Token generation should be a service.
        // For now, we keep the logic here but it could be injected.
        $token = base64_encode($user->id . ':' . time());

        return [
            'token' => $token,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value
            ]
        ];
    }
}
