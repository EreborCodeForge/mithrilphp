<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Fixtures\Guard;

final class SyntheticUserContext
{
    public static ?string $userId = null;

    public static ?string $workerLabel = null;

    private static ?string $token = null;

    public static function setToken(?string $token): void
    {
        self::$token = $token;
    }

    public static function clearRequestState(): void
    {
        self::$userId = null;
        self::$token = null;
    }

    public static function reset(): void
    {
        self::$userId = null;
        self::$workerLabel = null;
        self::$token = null;
    }
}
