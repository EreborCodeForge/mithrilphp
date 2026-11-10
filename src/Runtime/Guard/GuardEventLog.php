<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

/**
 * Sanitized finding log. Values, hashes, salts and request payloads are not fields.
 */
final class GuardEventLog
{
    private int $windowStartedNs = 0;

    private int $emittedInWindow = 0;

    /**
     * @param null|(callable(string): void) $writer
     */
    public function __construct(
        private readonly mixed $writer = null,
        private readonly int $maxPerWindow = 30,
        private readonly int $windowNs = 60_000_000_000,
    ) {}

    public static function toStderr(): self
    {
        return new self(static function (string $line): void {
            fwrite(STDERR, $line . PHP_EOL);
        });
    }

    public function finding(
        GuardMode $mode,
        GuardFinding $finding,
        int $requestOrdinal,
        string $action,
    ): void {
        $now = hrtime(true);
        if ($this->windowStartedNs === 0 || ($now - $this->windowStartedNs) >= $this->windowNs) {
            $this->windowStartedNs = $now;
            $this->emittedInWindow = 0;
        }
        if ($this->emittedInWindow >= $this->maxPerWindow) {
            return;
        }
        $this->emittedInWindow++;

        $payload = [
            'component' => 'mithril.worker_guard',
            'mode' => $mode->value,
            'event' => 'probe_finding',
            'probe_id' => GuardFinding::safeProbeId($finding->probeId),
            'code' => $finding->code,
            'severity' => $finding->severity->value,
            'confidence' => $finding->confidence->value,
            'scope' => $finding->scope,
            'request_ordinal' => $requestOrdinal,
            'action' => $action === 'recycle_after_response' ? 'recycle_after_response' : 'log',
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        if ($this->writer !== null) {
            ($this->writer)($encoded);
        }
    }
}
