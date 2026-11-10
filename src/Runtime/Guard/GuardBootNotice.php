<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

/**
 * Emits the invalid-config warning once per process.
 */
final class GuardBootNotice
{
    private static bool $emitted = false;

    /** @param resource|null $stream */
    public static function emit(GuardConfig $config, $stream = null): void
    {
        if (self::$emitted || $config->bootDiagnostic === null || $config->bootDiagnostic === '') {
            return;
        }
        self::$emitted = true;
        $line = $config->bootDiagnostic . PHP_EOL;
        if (is_resource($stream)) {
            fwrite($stream, $line);

            return;
        }
        fwrite(STDERR, $line);
    }

    public static function reset(): void
    {
        self::$emitted = false;
    }
}
