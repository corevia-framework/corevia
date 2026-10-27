<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Container
{
    private array $bindings = [];
    private array $factories = [];
    private array $resolving = [];

    public function set(string $id, object $value): void
    {
        $this->bindings[$id] = $value;
        unset($this->factories[$id]);
    }

    /** @param callable(self): object|class-string $factory */
    public function bind(string $id, callable|string $factory, bool $shared = false): void
    {
        $this->factories[$id] = ['factory' => $factory, 'shared' => $shared];
        unset($this->bindings[$id]);
    }

    /** @param callable(self): object|class-string $factory */
    public function singleton(string $id, callable|string $factory): void
    {
        $this->bind($id, $factory, true);
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->bindings[$id])) {
            return $this->bindings[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new \RuntimeException('Circular dependency: ' . $id);
        }
        $this->resolving[$id] = true;
        try {
            if (isset($this->factories[$id])) {
                $definition = $this->factories[$id];
                $factory = $definition['factory'];
                $value = is_string($factory) ? ($factory === $id ? $this->instantiate($id) : $this->get($factory)) : $factory($this);
                if (!is_object($value)) {
                    throw new \RuntimeException('Container factory must return an object: ' . $id);
                }
                if ($definition['shared']) {
                    $this->bindings[$id] = $value;
                }
                return $value;
            }
            return $this->instantiate($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    private function instantiate(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException('Bind a concrete service for: ' . $class);
        }
        $args = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            } else {
                throw new \RuntimeException('Cannot resolve ' . $class . '::$' . $parameter->getName());
            }
        }
        return $reflection->newInstanceArgs($args);
    }
}
