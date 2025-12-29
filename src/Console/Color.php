<?php

declare(strict_types=1);

namespace Erebor\Mithril\Core\Console;

class Color
{
    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";

    public static function red(string $text): string
    {
        return self::RED . $text . self::RESET;
    }

    public static function green(string $text): string
    {
        return self::GREEN . $text . self::RESET;
    }

    public static function yellow(string $text): string
    {
        return self::YELLOW . $text . self::RESET;
    }

    public static function blue(string $text): string
    {
        return self::BLUE . $text . self::RESET;
    }
}
