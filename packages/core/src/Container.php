<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Container
{
    private array $bindings = [];
    private array $resolving = [];

    public function set(string $id, object $value): void
    {
        $this->bindings[$id] = $value;
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
            $reflection = new \ReflectionClass($id);
            $args = [];
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $args[] = $this->get($type->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();
                } else {
                    throw new \RuntimeException('Cannot resolve ' . $id . '::$' . $parameter->getName());
                }
            }
            return $reflection->newInstanceArgs($args);
        } finally {
            unset($this->resolving[$id]);
        }
    }
}
