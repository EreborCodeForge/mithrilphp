<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\Auth\RegisterDTO;

interface RegisterUseCaseInterface
{
    public function execute(RegisterDTO $dto): array;
}
