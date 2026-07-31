<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionNamedType;

final class Container
{

    /**
     * @var array<string, callable(self): object>
     */
    private array $compiledFactories = [];

    /**
     * @var array<string, callable(self): object>
     */
    private array $compiledSingletons = [];

    /**
     * Warm baseline restored by resetWorker() (typically compiled preloaded).
     *
     * @var array<string, object>
     */
    private array $warmBaseline = [];

    private bool $compiled = false;
    private bool $compiledStrict = true;

    /**
     * @var array<string, callable|string>
     */
    private array $bindings = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, object>
     */
    private array $scopedInstances = [];

    /**
     * @var array<string, callable|string>
     */
    private array $runtimeScoped = [];

    /**
     * @var array<string, array>
     */
    private array $classMetaCache = [];

    public function loadCompiled(
        array $factories,
        array $singletons,
        array $preloaded = [],
        bool $strict = true
    ): void {
        $this->compiled = true;
        $this->compiledStrict = $strict;

        $this->compiledFactories = $factories;
        $this->compiledSingletons = $singletons;
        $this->warmBaseline = $preloaded;
        $this->instances = $preloaded;
    }

    public function isCompiled(): bool
    {
        return $this->compiled;
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        return isset($this->instances[$abstract])
            || isset($this->bindings[$abstract])
            || isset($this->compiledFactories[$abstract])
            || isset($this->compiledSingletons[$abstract])
            || isset($this->runtimeScoped[$abstract]);
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
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, callable|string|object $concrete): void
    {
        if (is_object($concrete) && !is_callable($concrete)) {
            $this->instances[$abstract] = $concrete;
            return;
        }

        $this->bindings[$abstract] = function (self $c) use ($concrete, $abstract) {
            if (!isset($c->instances[$abstract])) {
                $c->instances[$abstract] = is_callable($concrete)
                    ? $concrete($c)
                    : $c->resolve($concrete);
            }
            return $c->instances[$abstract];
        };
    }

    public function scoped(string $abstract, callable|string $concrete): void
    {
        $this->runtimeScoped[$abstract] = $concrete;
    }

    public function get(string $abstract): object
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->runtimeScoped[$abstract])) {
            if (isset($this->scopedInstances[$abstract])) {
                return $this->scopedInstances[$abstract];
            }

            $concrete = $this->runtimeScoped[$abstract];
            return $this->scopedInstances[$abstract] = is_callable($concrete)
                ? $concrete($this)
                : $this->resolve($concrete);
        }

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->compiledSingletons[$abstract])) {
            $obj = ($this->compiledSingletons[$abstract])($this);
            return $this->instances[$abstract] = $obj;
        }

        if (isset($this->compiledFactories[$abstract])) {
            return ($this->compiledFactories[$abstract])($this);
        }

        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];

            if (is_callable($concrete)) {
                return $concrete($this);
            }

            return $this->resolve($concrete);
        }

        if ($this->compiled && $this->compiledStrict) {
            throw new ContainerException("Service [$abstract] not in compiled container.");
        }

        return $this->resolve($abstract);
    }

    public function resolve(string $concrete): object
    {
        if (!class_exists($concrete)) {
            throw new ContainerException("Class {$concrete} not found.");
        }

        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class {$concrete} not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if (!$constructor) {
            return new $concrete();
        }

        $deps = $this->resolveConstructorDeps($concrete, $constructor->getParameters());
        return $reflector->newInstanceArgs($deps);
    }

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

            $meta[] = ['type' => 'class', 'name' => $type->getName()];
        }

        return $meta;
    }

    public function beginScope(): void
    {
        $this->scopedInstances = [];
    }

    public function endScope(): void
    {
        foreach ($this->scopedInstances as $obj) {
            if (method_exists($obj, 'cleanup')) {
                $obj->cleanup();
            }
        }
        $this->scopedInstances = [];
    }

    public function resetWorker(): void
    {
        $this->scopedInstances = [];
        $this->instances = $this->warmBaseline;
    }
}
