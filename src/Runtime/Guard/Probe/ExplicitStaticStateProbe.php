<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard\Probe;

use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardFinding;
use Erebor\Mithril\Runtime\Guard\GuardProbe;
use Erebor\Mithril\Runtime\Guard\StaticWatchpoint;
use ReflectionClass;
use ReflectionProperty;

/**
 * Compares explicitly registered static properties with a salted fingerprint.
 * The raw value is never stored or reported. Non-scalars contribute only a type tag.
 */
final class ExplicitStaticStateProbe implements GuardProbe
{
    private readonly string $salt;

    /** @var array<int, string>|null */
    private ?array $baseline = null;

    /** @var array<int, ReflectionProperty> */
    private array $properties = [];

    private bool $partial = false;

    /**
     * @param list<StaticWatchpoint> $watchpoints
     */
    public function __construct(
        private readonly array $watchpoints,
    ) {
        $this->salt = bin2hex(random_bytes(16));
    }

    public function id(): string
    {
        return 'static_watchpoints';
    }

    public function before(GuardCycleContext $context): void
    {
        if ($this->baseline !== null) {
            return;
        }

        $this->baseline = $this->fingerprints();
    }

    public function after(GuardCycleContext $context): array
    {
        if ($context->collectionIncomplete || $this->baseline === null) {
            return [];
        }

        $current = $this->fingerprints();
        foreach ($this->baseline as $index => $expected) {
            if (($current[$index] ?? null) !== $expected) {
                return [GuardFinding::stateRetained($this->id())];
            }
        }

        return [];
    }

    public function coverage(): string
    {
        $hasRequest = false;
        foreach ($this->watchpoints as $watchpoint) {
            if ($watchpoint->lifetime === 'request') {
                $hasRequest = true;
                break;
            }
        }
        if (!$hasRequest) {
            return 'not_configured';
        }
        if ($this->partial) {
            return 'partial';
        }
        if ($this->baseline === null) {
            return 'skipped';
        }

        return 'covered';
    }

    /**
     * @return array<int, string>
     */
    private function fingerprints(): array
    {
        $out = [];
        foreach ($this->watchpoints as $index => $watchpoint) {
            if ($watchpoint->lifetime !== 'request') {
                continue;
            }
            $out[$index] = $this->fingerprint($watchpoint, $index);
        }

        return $out;
    }

    private function fingerprint(StaticWatchpoint $watchpoint, int $index): string
    {
        $property = $this->property($watchpoint, $index);
        $value = $property->getValue();
        $type = get_debug_type($value);
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $type . ':' . hash('xxh128', $this->salt . '|' . $type . '|' . $this->canonical($value));
        }

        $this->partial = true;

        return 'nonscalar:' . $type;
    }

    private function property(StaticWatchpoint $watchpoint, int $index): ReflectionProperty
    {
        if (isset($this->properties[$index])) {
            return $this->properties[$index];
        }

        if (!class_exists($watchpoint->class)) {
            throw new \RuntimeException('watchpoint unavailable');
        }

        $class = new ReflectionClass($watchpoint->class);
        if (!$class->hasProperty($watchpoint->property)) {
            throw new \RuntimeException('watchpoint unavailable');
        }

        $property = $class->getProperty($watchpoint->property);
        if (!$property->isStatic()) {
            throw new \RuntimeException('watchpoint unavailable');
        }

        return $this->properties[$index] = $property;
    }

    private function canonical(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'nan';
            }

            return sprintf('%.17g', $value);
        }

        return (string) $value;
    }
}
