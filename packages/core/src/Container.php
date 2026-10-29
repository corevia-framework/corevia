<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Container
{
    private array $bindings = [];
    private array $factories = [];
    private array $resolving = [];
    private array $sharedResolution = [];
    public function __construct(private readonly ScopeManager $scopes = new ScopeManager()) {}

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

    public function scoped(string $id, callable|string $factory, ?callable $dispose = null): void
    {
        $this->bind($id, $factory); $this->factories[$id]['scoped'] = true; $this->factories[$id]['dispose'] = $dispose;
    }
    public function scopes(): ScopeManager { return $this->scopes; }
    /** Declaration metadata only; never serialize service instances or captured runtime state. */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->bindings as $id => $value) { $definitions[$id] = ['lifetime' => 'instance', 'class' => $value::class]; }
        foreach ($this->factories as $id => $entry) {
            $factory = $entry['factory'];
            $description = is_string($factory) ? ['class' => $factory] : ['factory' => true];
            if (!is_string($factory)) {
                $reflection = new \ReflectionFunction(\Closure::fromCallable($factory)); $file = $reflection->getFileName();
                $description += ['source' => $file === false ? null : hash_file('sha256', $file), 'line' => $reflection->getStartLine(), 'end' => $reflection->getEndLine()];
            }
            $definitions[$id] = ['lifetime' => ($entry['scoped'] ?? false) ? 'scope' : ($entry['shared'] ? 'singleton' : 'transient'), ...$description];
        }
        ksort($definitions, SORT_STRING); return DeclarationIdentity::normalize($definitions);
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
        $scope = $this->scopes->current(); $context = $scope?->id ?? 'global'; $resolutionKey = $context . ':' . $id;
        if (($this->factories[$id]['scoped'] ?? false) && ($this->sharedResolution[$context] ?? 0) > 0) { throw new \LogicException('A singleton cannot capture a scoped service.'); }
        if (isset($this->resolving[$resolutionKey])) {
            throw new \RuntimeException('Circular dependency: ' . $id);
        }
        $this->resolving[$resolutionKey] = true;
        try {
            if (isset($this->factories[$id])) {
                $definition = $this->factories[$id];
                $factory = $definition['factory'];
                $create = fn (): mixed => is_string($factory) ? ($factory === $id ? $this->instantiate($id) : $this->get($factory)) : $factory($this);
                if ($definition['scoped'] ?? false) {
                    if ($scope === null) { throw new \LogicException('Scoped services require an execution scope.'); }
                    return $scope->remember($id, $create, $definition['dispose']);
                }
                if ($definition['shared']) { $this->sharedResolution[$context] = ($this->sharedResolution[$context] ?? 0) + 1; }
                try { $value = $create(); }
                finally { if ($definition['shared'] && --$this->sharedResolution[$context] === 0) { unset($this->sharedResolution[$context]); } }
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
            unset($this->resolving[$resolutionKey]);
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
