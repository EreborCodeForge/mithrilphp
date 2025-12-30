<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionNamedType;

final class Container
{
    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, callable|string>
     */
    private array $bindings = [];

    /**
     * @param string              $abstract
     * @param callable|string     $concrete  Closure(Container $c): object ou FQCN
     */
    public function bind(string $abstract, callable|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     *
     * @param string                    $abstract
     * @param callable|string|object    $concrete
     */
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

    /**
     *
     * @throws ContainerException
     */
    public function get(string $abstract): mixed
    {
        if ($this->hasInstance($abstract)) {
            return $this->instances[$abstract];
        }

        if ($this->hasBinding($abstract)) {
            return $this->resolveFromBinding($abstract);
        }

        return $this->resolve($abstract);
    }

    /**
     *
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

        $dependencies = $this->resolveDependencies($constructor->getParameters());

        return $reflector->newInstanceArgs($dependencies);
    }

    private function hasInstance(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }

    public function hasBinding(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    /**
     *
     * @throws ContainerException
     */
    private function resolveFromBinding(string $abstract): object
    {
        $concrete = $this->bindings[$abstract];

        if (is_callable($concrete)) {
            $instance = $concrete($this);

            if (!is_object($instance)) {
                throw new ContainerException(
                    "Binding for {$abstract} must return an object, " . gettype($instance) . " given."
                );
            }

            return $instance;
        }

        return $this->resolve($concrete);
    }

    /**
     *
     * @param \ReflectionParameter[] $parameters
     *
     * @return array<int, mixed>
     *
     * @throws ContainerException
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $dependencies[] = $this->resolveNonClassDependency($parameter);
                continue;
            }

            $dependencyClass = $type->getName();
            $dependencies[] = $this->get($dependencyClass);
        }

        return $dependencies;
    }

    /**
     * @throws ContainerException
     */
    private function resolveNonClassDependency(\ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException(
            sprintf('Cannot resolve parameter "%s" in %s::__construct()',
                $parameter->getName(),
                $parameter->getDeclaringClass()?->getName() ?? 'unknown'
            )
        );
    }
}
