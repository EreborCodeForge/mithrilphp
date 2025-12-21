<?php

declare(strict_types=1);

namespace App\Domain\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case USER = 'user';
}
