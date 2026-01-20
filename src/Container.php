<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionNamedType;

final class Container
{
    /**
     * Runtime compiled mode:
     * @var array<string, callable(self): object>
     */
    private array $factories = [];

    /**
     * @var array<string, callable(self): object>
     */
    private array $singletons = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];
    private array $bindings = [];
    private array $classMetaCache = [];
    private bool $compiled = false;
    private bool $compiledStrict = true;

    /**
     *
     * @param array<string, callable(self): object> $factories
     * @param array<string, callable(self): object> $singletons
     * @param array<string, object> $preloaded
     */
    public function loadCompiled(
        array $factories,
        array $singletons,
        array $preloaded = [],
        bool $strict = true
    ): void {
        $this->compiled = true;
        $this->compiledStrict = $strict;

        $this->factories = $factories;
        $this->singletons = $singletons;
        $this->instances = $preloaded;
    }

    public function isCompiled(): bool
    {
        return $this->compiled;
    }

    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function bind(string $abstract, callable|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function factory(string $abstract, callable|string $concrete): void
    {
        $this->bind($abstract, $concrete);
    }

    public function singleton(string $abstract, callable|string|object $concrete): void
    {
        if (is_object($concrete) && !is_callable($concrete)) {
            $this->instances[$abstract] = $concrete;
            return;
        }

        $this->bindings[$abstract] = function (self $container) use ($concrete, $abstract): object {
            if (!isset($container->instances[$abstract])) {
                $container->instances[$abstract] = is_callable($concrete)
                    ? $concrete($container)
                    : $container->resolve($concrete);
            }

            return $container->instances[$abstract];
        };
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->factories[$abstract])
            || isset($this->bindings[$abstract])
            || (!$this->compiledStrict && class_exists($abstract));
    }

    /**
     * @throws ContainerException
     */
    public function get(string $abstract): object
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->singletons[$abstract])) {
            $factory = $this->singletons[$abstract];

            $obj = $factory($this);
            if (!is_object($obj)) {
                throw new ContainerException("Compiled singleton [$abstract] must return object.");
            }

            $this->instances[$abstract] = $obj;
            unset($this->singletons[$abstract]);

            return $obj;
        }

        if (isset($this->factories[$abstract])) {
            $factory = $this->factories[$abstract];

            $obj = $factory($this);
            if (!is_object($obj)) {
                throw new ContainerException("Compiled factory [$abstract] must return object.");
            }

            return $obj;
        }

        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];

            if (is_callable($concrete)) {
                $obj = $concrete($this);
                if (!is_object($obj)) {
                    throw new ContainerException("Binding [$abstract] must return object.");
                }
                return $obj;
            }

            return $this->resolve($concrete);
        }

        // 5) Strict compiled: no reflection allowed
        if ($this->compiled && $this->compiledStrict) {
            throw new ContainerException("Service [$abstract] not found in compiled container.");
        }

        // 6) Dev fallback autowire
        return $this->resolve($abstract);
    }

    /**
     * DEV ONLY autowire (Reflection)
     * @throws ContainerException
     */
    public function resolve(string $concrete): object
    {
        if (!class_exists($concrete)) {
            throw new ContainerException("Class {$concrete} not found.");
        }

        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class {$concrete} is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $deps = $this->resolveConstructorDeps($concrete, $constructor->getParameters());

        return $reflector->newInstanceArgs($deps);
    }

    /**
     * @param class-string $concrete
     * @param \ReflectionParameter[] $params
     */
    private function resolveConstructorDeps(string $concrete, array $params): array
    {
        $meta = $this->classMetaCache[$concrete] ??= $this->buildClassMeta($params);

        $deps = [];

        foreach ($meta as $dep) {
            if ($dep['type'] === 'class') {
                $deps[] = $this->get($dep['name']);
                continue;
            }

            if (array_key_exists('default', $dep)) {
                $deps[] = $dep['default'];
                continue;
            }

            throw new ContainerException("Cannot resolve builtin dependency for {$concrete}");
        }

        return $deps;
    }

    /**
     * @param \ReflectionParameter[] $parameters
     */
    private function buildClassMeta(array $parameters): array
    {
        $meta = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $meta[] = $parameter->isDefaultValueAvailable()
                    ? ['type' => 'builtin', 'default' => $parameter->getDefaultValue()]
                    : ['type' => 'builtin'];
                continue;
            }

            $meta[] = [
                'type' => 'class',
                'name' => $type->getName(),
            ];
        }

        return $meta;
    }
}
