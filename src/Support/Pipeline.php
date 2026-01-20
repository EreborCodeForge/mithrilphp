<?php

declare(strict_types=1);

namespace Erebor\Mithril\Support;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\PipelineContract;
use RuntimeException;

/**
 * SINGLE Pipeline:
 * - high performance
 * - iterative execution
 * - factory cache (safe)
 * - supports callable, object, class-string
 *
 * Rules:
 * - middleware/pipe must implement handle($payload, $next)
 * - middleware must be stateless
 */
final class Pipeline implements PipelineContract
{
    private mixed $payload = null;

    /**
     * @var array<int, callable|string|object>
     */
    private array $pipes = [];

    /**
     * hash => array<int, callable(): callable|object>
     *
     * @var array<string, array<int, callable>>
     */
    private array $factoryCache = [];

    private ?Container $container = null;

    public function __construct(?Container $container = null) {
        $this->container = $container;
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    public function send(mixed $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * @param array<int, callable|string|object> $pipes
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;
        return $this;
    }

    public function pipe(callable|string|object $pipe): self
    {
        $this->pipes[] = $pipe;
        return $this;
    }

    public function reset(): self
    {
        $this->payload = null;
        $this->pipes = [];
        return $this;
    }

    /**
     * @param callable $destination fn(mixed $payload): mixed
     */
    public function then(callable $destination): mixed
    {
        $payload = $this->payload;
        $factories = $this->resolveFactories($this->pipes);

        $count = count($factories);
        $index = 0;

        $next = function (mixed $currentPayload) use (
            &$index,
            $count,
            $factories,
            $destination,
            &$next
        ) {
            if ($index >= $count) {
                return $destination($currentPayload);
            }

            $factory = $factories[$index];
            $index++;

            $pipe = $factory();

            if (is_callable($pipe)) {
                return $pipe($currentPayload, $next);
            }

            if (is_object($pipe)) {
                if (method_exists($pipe, 'handle')) {
                    return $pipe->handle($currentPayload, $next);
                }

                if (method_exists($pipe, '__invoke')) {
                    return $pipe($currentPayload, $next);
                }
            }

            throw new RuntimeException('Pipeline: resolved pipe is invalid.');
        };

        return $next($payload);
    }

    /**
     * Caches by pipeline hash:
     * Each item becomes a factory fn() => pipe
     *
     * @param array<int, callable|string|object> $pipes
     * @return array<int, callable(): mixed>
     */
    private function resolveFactories(array $pipes): array
    {
        $hash = $this->pipesHash($pipes);

        if (isset($this->factoryCache[$hash])) {
            return $this->factoryCache[$hash];
        }

        $factories = [];

        foreach ($pipes as $pipe) {
            if (is_callable($pipe) && !is_string($pipe)) {
                $factories[] = static fn() => $pipe;
                continue;
            }

            if (is_object($pipe)) {
                $factories[] = static fn() => $pipe;
                continue;
            }

            if (is_string($pipe)) {
                if (!$this->container) {
                    throw new RuntimeException(
                        "Pipeline: pipe [$pipe] requires container for class-string resolution."
                    );
                }
                $factories[] = fn() => $this->container->get($pipe);
                continue;
            }

            $type = gettype($pipe);

            throw new RuntimeException("Pipeline: invalid pipe type [$type].");
        }

        return $this->factoryCache[$hash] = $factories;
    }

    /**
     * Deterministic pipeline hash.
     * - string: class name
     * - object/callable: object_id
     */
    private function pipesHash(array $pipes): string
    {
        $parts = [];

        foreach ($pipes as $p) {
            if (is_string($p)) {
                $parts[] = 'S:' . $p;
                continue; 
            }

            if (is_object($p)) {
                $parts[] = 'O:' . get_class($p) . '#' . spl_object_id($p);
                continue;
            }

            if ($p instanceof \Closure) {
                $parts[] = 'C:' . spl_object_id($p);
                continue;
            }

            if (is_callable($p)) {
                $parts[] = 'K:' . spl_object_id((object)$p);
                continue;
            }

            $parts[] = 'U:' . gettype($p);
        }

        return hash('xxh128', implode('|', $parts));
    }
}
