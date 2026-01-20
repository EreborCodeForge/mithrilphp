<?php

declare(strict_types=1);

namespace Erebor\Mithril\Container;

use Erebor\Mithril\Exceptions\ContainerException;

final class CompiledContainer
{
    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, callable(self): object>
     */
    private array $factories = [];

    /**
     * @var array<string, callable(self): object>
     */
    private array $singletons = [];

    /**
     * @param array<string, callable(self): object> $factories
     * @param array<string, callable(self): object> $singletons
     * @param array<string, object> $preloaded
     */
    public function __construct(array $factories, array $singletons, array $preloaded = [])
    {
        $this->factories = $factories;
        $this->singletons = $singletons;
        $this->instances = $preloaded;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->singletons[$id])
            || isset($this->factories[$id]);
    }

    /**
     * @throws ContainerException
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->singletons[$id])) {
            $factory = $this->singletons[$id];
            $obj = $factory($this);

            if (!is_object($obj)) {
                throw new ContainerException("Compiled singleton [$id] must return object.");
            }

            $this->instances[$id] = $obj;
            unset($this->singletons[$id]);

            return $obj;
        }

        if (isset($this->factories[$id])) {
            $factory = $this->factories[$id];
            $obj = $factory($this);

            if (!is_object($obj)) {
                throw new ContainerException("Compiled factory [$id] must return object.");
            }

            return $obj;
        }

        throw new ContainerException("Service [$id] not found in compiled container.");
    }
}
