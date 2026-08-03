<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;

final readonly class WorkerCliOptions
{
    public function __construct(
        public string $socket,
        public string $workerId,
        public int $generation,
        public int $maxRequests,
        public int $memoryLimitMb,
        public string $manifest,
    ) {}

    /**
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        $opts = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                throw new ProtocolException("Unexpected argument: {$arg}");
            }

            $eq = strpos($arg, '=');
            if ($eq === false) {
                throw new ProtocolException("Flag requires value: {$arg}");
            }

            $key = substr($arg, 2, $eq - 2);
            $value = substr($arg, $eq + 1);
            $opts[$key] = $value;
        }

        foreach (['socket', 'worker-id', 'generation', 'max-requests', 'memory-limit-mb', 'manifest'] as $required) {
            if (!array_key_exists($required, $opts) || $opts[$required] === '') {
                throw new ProtocolException("Missing required flag --{$required}");
            }
        }

        return new self(
            socket: $opts['socket'],
            workerId: $opts['worker-id'],
            generation: self::uint($opts['generation'], 'generation'),
            maxRequests: self::intNonNegative($opts['max-requests'], 'max-requests'),
            memoryLimitMb: self::intNonNegative($opts['memory-limit-mb'], 'memory-limit-mb'),
            manifest: $opts['manifest'],
        );
    }

    public function memoryLimitBytes(): int
    {
        if ($this->memoryLimitMb <= 0) {
            return 0;
        }

        return $this->memoryLimitMb * 1024 * 1024;
    }

    private static function uint(string $value, string $name): int
    {
        if (!ctype_digit($value)) {
            throw new ProtocolException("Invalid --{$name}: {$value}");
        }

        return (int) $value;
    }

    private static function intNonNegative(string $value, string $name): int
    {
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new ProtocolException("Invalid --{$name}: {$value}");
        }

        $int = (int) $value;
        if ($int < 0) {
            throw new ProtocolException("Invalid --{$name}: must be >= 0");
        }

        return $int;
    }
}
