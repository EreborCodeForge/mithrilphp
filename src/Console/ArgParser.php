<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console;

/**
 * Minimal --key=value / --flag parser for Forge commands.
 */
final class ArgParser
{
    /**
     * @param list<string> $args
     * @return array{options: array<string, string|bool>, positionals: list<string>}
     */
    public static function parse(array $args): array
    {
        $options = [];
        $positionals = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $positionals[] = $arg;
                continue;
            }

            $body = substr($arg, 2);
            $eq = strpos($body, '=');
            if ($eq === false) {
                $options[$body] = true;
                continue;
            }

            $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }

        return ['options' => $options, 'positionals' => $positionals];
    }

    /**
     * @param array<string, string|bool> $options
     */
    public static function string(array $options, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }

        $value = $options[$key];
        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, string|bool> $options
     */
    public static function int(array $options, string $key, int $default): int
    {
        $value = self::string($options, $key);
        if ($value === null || !preg_match('/^-?\d+$/', $value)) {
            return $default;
        }

        return (int) $value;
    }
}
