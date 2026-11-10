<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

final readonly class GuardConfig
{
    public const HTTP_DISABLED_DIAGNOSTIC = 'WorkerGuard invalid configuration; guard disabled for this worker. Run `durin doctor --strict`';
    /**
     * @param list<StaticWatchpoint> $watchpoints
     */
    public function __construct(
        public GuardMode $mode,
        public int $maxProbesPerCycle,
        public int $sampleEveryNRequests,
        public string $reportPath,
        public bool $recycleOnVerifiedStateViolation,
        public array $watchpoints,
        public bool $enableScopedProbe = true,
        public bool $enableStaticProbe = true,
        public bool $enableMemoryProbe = true,
        public ?string $bootDiagnostic = null,
    ) {}

    public static function off(): self
    {
        return new self(GuardMode::Off, 8, 10, 'var/worker-guard', false, []);
    }

    /**
     * CLI audit always runs probes. Recycle stays off.
     *
     * @param list<StaticWatchpoint> $watchpoints
     */
    public static function audit(array $watchpoints): self
    {
        return new self(
            GuardMode::Audit,
            8,
            10,
            'var/worker-guard',
            false,
            $watchpoints,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, bool $httpWorker = false): self
    {
        $allowed = [
            'mode' => true,
            'max_probes_per_cycle' => true,
            'sample_every_n_requests' => true,
            'report_path' => true,
            'policy' => true,
            'static_watchpoints' => true,
        ];
        foreach ($data as $key => $_) {
            if (!isset($allowed[$key])) {
                throw new GuardConfigException('Invalid worker guard config');
            }
        }

        $mode = self::mode($data['mode'] ?? 'off', $httpWorker);
        $maxProbes = self::intRange($data['max_probes_per_cycle'] ?? 8, 1, 8);
        $sample = self::intRange($data['sample_every_n_requests'] ?? 10, 1, 1000);
        $reportPath = self::reportPath($data['report_path'] ?? 'var/worker-guard');
        $policy = self::policy(is_array($data['policy'] ?? null) ? $data['policy'] : []);
        $watchpoints = self::watchpoints($data['static_watchpoints'] ?? []);

        return new self(
            $mode,
            $maxProbes,
            $sample,
            $reportPath,
            $policy,
            $watchpoints,
        );
    }

    public static function loadFile(string $path, bool $httpWorker): self
    {
        if (!is_file($path)) {
            return self::off();
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new GuardConfigException('worker_guard.php must return an array');
        }

        return self::fromArray($data, $httpWorker);
    }

    public static function httpWorker(string $workingDirectory): self
    {
        $path = rtrim($workingDirectory, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'worker_guard.php';
        if (!is_file($path)) {
            return self::off();
        }

        try {
            return self::loadFile($path, true);
        } catch (GuardConfigException) {
            $off = self::off();

            return new self(
                $off->mode,
                $off->maxProbesPerCycle,
                $off->sampleEveryNRequests,
                $off->reportPath,
                $off->recycleOnVerifiedStateViolation,
                $off->watchpoints,
                bootDiagnostic: self::HTTP_DISABLED_DIAGNOSTIC,
            );
        }
    }

    private static function mode(mixed $value, bool $httpWorker): GuardMode
    {
        if (!is_string($value)) {
            throw new GuardConfigException('Invalid worker guard mode');
        }

        $mode = GuardMode::tryFrom($value);
        if ($mode === null) {
            throw new GuardConfigException('Invalid worker guard mode');
        }
        if ($httpWorker && $mode === GuardMode::Audit) {
            throw new GuardConfigException('Audit mode is not valid for the HTTP worker');
        }

        return $mode;
    }

    private static function intRange(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new GuardConfigException('Invalid worker guard config');
        }

        return $value;
    }

    private static function reportPath(mixed $value): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0") || str_contains($value, '..')) {
            throw new GuardConfigException('Invalid worker guard config');
        }
        if ($value[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $value) === 1) {
            throw new GuardConfigException('Invalid worker guard config');
        }

        return str_replace('\\', '/', $value);
    }

    /**
     * @param array<mixed, mixed> $policy
     */
    private static function policy(array $policy): bool
    {
        $allowed = [
            'recycle_on_verified_state_violation' => true,
            'recycle_on_suspected_memory_growth' => true,
        ];
        foreach ($policy as $key => $_) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new GuardConfigException('Invalid worker guard config');
            }
        }

        $memory = $policy['recycle_on_suspected_memory_growth'] ?? false;
        if ($memory !== false) {
            throw new GuardConfigException('recycle_on_suspected_memory_growth must be false');
        }

        $state = $policy['recycle_on_verified_state_violation'] ?? false;
        if (!is_bool($state)) {
            throw new GuardConfigException('Invalid worker guard config');
        }

        return $state;
    }

    /**
     * @return list<StaticWatchpoint>
     */
    private static function watchpoints(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new GuardConfigException('Invalid watchpoint');
        }
        if (count($rows) > 32) {
            throw new GuardConfigException('Invalid watchpoint');
        }

        $watchpoints = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new GuardConfigException('Invalid watchpoint');
            }
            $watchpoints[] = StaticWatchpoint::fromArray($row);
        }

        return $watchpoints;
    }
}
