<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

use ArrayIterator;
use IteratorAggregate;

final class AttributeBag implements IteratorAggregate
{
    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    public function __construct(array $initial = [])
    {
        $this->items = $initial;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * @return mixed removed value
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, $this->items)) {
            return $default;
        }

        $value = $this->items[$key];
        unset($this->items[$key]);

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    /**
     * @return bool true if removed
     */
    public function remove(string $key): bool
    {
        if (!\array_key_exists($key, $this->items)) {
            return false;
        }

        unset($this->items[$key]);
        return true;
    }

    public function forget(string $key): void
    {
        // keep backward compatibility
        unset($this->items[$key]);
    }

    /**
     * Replace all attributes.
     * @param array<string, mixed> $attributes
     */
    public function replace(array $attributes): void
    {
        $this->items = $attributes;
    }

    /**
     * Merge new items on top of current ones (override).
     * @param array<string, mixed> $attributes
     */
    public function merge(array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        // array_replace is faster than foreach for flat arrays
        $this->items = \array_replace($this->items, $attributes);
    }

    /**
     * Set only if missing.
     */
    public function setIfMissing(string $key, mixed $value): mixed
    {
        // direct check faster than has()
        if (!\array_key_exists($key, $this->items)) {
            $this->items[$key] = $value;
        }

        return $this->items[$key];
    }

    /**
     * Cache a computed value once (request-scoped).
     *
     * IMPORTANT:
     * We DO NOT store the closure, only the computed value.
     * This avoids accidental context/closure leaks in long-running workers.
     */
    public function once(string $key, callable $factory): mixed
    {
        if (\array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $value = $factory();

        // store only value (never closure)
        $this->items[$key] = $value;

        return $value;
    }

    /**
     * Clear all attributes.
     * Essential for long-running workers to avoid request memory leaks.
     */
    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * Alias for clear() (DX friendly).
     */
    public function reset(): void
    {
        $this->items = [];
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }
}
