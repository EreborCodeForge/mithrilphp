<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\Auth\LoginDTO;

interface LoginUseCaseInterface
{
    public function execute(LoginDTO $dto): array;
}
