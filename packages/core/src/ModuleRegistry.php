<?php

declare(strict_types=1);

namespace Corevia\Core;

final class ModuleRegistry
{
    private array $modules = [];
    private array $health = [];
    private array $contexts = [];
    private array $manifests = [];
    private array $started = [];
    private bool $registered = false;
    private bool $activated = false;
    private ?Container $container = null;
    private ?Capabilities $capabilities = null;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function add(Module $module, bool $enabled = true): void
    {
        $name = $module->name();
        if (isset($this->modules[$name])) {
            throw new \InvalidArgumentException('Duplicate module: ' . $name);
        }
        if ($this->registered) {
            throw new \LogicException('Register modules before boot.');
        }
        $this->modules[$name] = $module;
        $this->health[$name] = ['status' => $enabled ? 'pending' : 'disabled', 'errors' => []];
    }

    public function boot(array $config = [], ?Container $container = null, ?Capabilities $capabilities = null): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        $this->container = $container;
        $this->capabilities = $capabilities ?? new Capabilities();
        foreach (array_keys($this->modules) as $name) {
            $this->bootOne($name, [], $config);
        }
    }

    private function bootOne(string $name, array $stack, array $config): bool
    {
        $status = $this->health[$name]['status'] ?? 'missing';
        if ($status !== 'pending') {
            return $status === 'healthy';
        }
        try {
            if (in_array($name, $stack, true)) {
                throw new \RuntimeException('Circular module dependency.');
            }
            $module = $this->modules[$name];
            $manifest = $module instanceof ManifestProvider ? $module->manifest() : new ModuleManifest($name, dependencies: $module->dependencies());
            $declared = $module->dependencies();
            $manifestDependencies = $manifest->dependencies;
            sort($declared);
            sort($manifestDependencies);
            if ($manifest->id !== $name || $declared !== $manifestDependencies) {
                throw new \RuntimeException('Manifest ID/dependencies must match the Module contract.');
            }
            $this->manifests[$name] = $manifest;
            foreach ($manifest->requires as $capability) {
                if (!$this->capabilities->has($capability)) {
                    throw new \RuntimeException('Unavailable capability: ' . $capability);
                }
            }
            foreach ($this->modules[$name]->dependencies() as $dependency) {
                if (!$this->bootOne($dependency, [...$stack, $name], $config)) {
                    throw new \RuntimeException('Unavailable dependency: ' . $dependency);
                }
            }
            $context = new ModuleContext($name, ConfigRepository::merge($manifest->defaults, $config[$name] ?? []), $this->container, $manifest);
            $this->modules[$name]->register($context);
            // A flow may only execute actions from its own module or declared dependencies.
            $permitted = $context->registeredActions;
            $collect = function (string $dependency) use (&$collect, &$permitted): void {
                $permitted += $this->contexts[$dependency]->registeredActions;
                foreach ($this->modules[$dependency]->dependencies() as $nested) {
                    $collect($nested);
                }
            };
            foreach ($this->modules[$name]->dependencies() as $dependency) {
                $collect($dependency);
            }
            foreach ($context->registeredFlows as $flow) {
                foreach ($flow->steps as $step) {
                    if (!isset($permitted[$step->action]) && !in_array($step->action, $permitted, true)) {
                        throw new \RuntimeException('Undeclared action dependency: ' . $step->action);
                    }
                }
            }
            foreach ($this->contexts as $existing) {
                if (array_intersect_key($existing->registeredActions, $context->registeredActions) || array_intersect_key($existing->registeredFlows, $context->registeredFlows)) {
                    throw new \RuntimeException('Duplicate action or flow registration.');
                }
            }
            $this->contexts[$name] = $context;
            $context->freeze();
            $this->health[$name]['status'] = 'healthy';
            return true;
        } catch (\Throwable $exception) {
            $this->health[$name] = ['status' => 'failed', 'errors' => [$exception->getMessage()]];
            $this->logger->log('error', 'Module registration failed', ['module' => $name, 'exception' => $exception::class]);
            return false;
        }
    }

    public function health(): array
    {
        return $this->health;
    }

    public function contexts(): array
    {
        return array_filter($this->contexts, fn (ModuleContext $context): bool => $this->health[$context->module]['status'] === 'healthy');
    }

    public function manifests(): array
    {
        return $this->manifests;
    }

    /** Boot/ready in dependency order; a failed lifecycle hook disables its dependents. */
    public function activate(): void
    {
        if (!$this->registered) {
            throw new \LogicException('Register modules first.');
        }
        if ($this->activated) {
            return;
        }
        $this->activated = true;
        foreach (['boot', 'ready'] as $phase) {
            foreach ($this->contexts as $name => $context) {
                if ($this->health[$name]['status'] !== 'healthy') {
                    continue;
                }
                foreach ($this->modules[$name]->dependencies() as $dependency) {
                    if ($this->health[$dependency]['status'] !== 'healthy') {
                        $this->health[$name] = ['status' => 'failed', 'phase' => $phase, 'errors' => ['Unavailable dependency: ' . $dependency]];
                        break;
                    }
                }
                if ($this->health[$name]['status'] !== 'healthy') {
                    if (isset($this->started[$name])) {
                        $this->shutdownOne($name);
                    }
                    continue;
                }
                $module = $this->modules[$name];
                if (!$module instanceof ModuleLifecycle) {
                    continue;
                }
                try {
                    if ($phase === 'boot') {
                        $this->started[$name] = true;
                    }
                    $module->$phase($context);
                } catch (\Throwable $exception) {
                    $this->health[$name] = ['status' => 'failed', 'phase' => $phase, 'errors' => ['Module ' . $phase . ' hook failed.']];
                    $this->logger->log('error', 'Module lifecycle failed', ['module' => $name, 'phase' => $phase, 'exception' => $exception::class]);
                    $affected = [$name => true];
                    do {
                        $previous = count($affected);
                        foreach ($this->contexts as $dependent => $dependentContext) {
                            if (!isset($affected[$dependent]) && array_intersect($this->modules[$dependent]->dependencies(), array_keys($affected))) {
                                $affected[$dependent] = true;
                                $this->health[$dependent] = ['status' => 'failed', 'phase' => $phase, 'errors' => ['Unavailable lifecycle dependency: ' . $name]];
                            }
                        }
                    } while (count($affected) !== $previous);
                    foreach (array_reverse(array_keys($this->started)) as $started) {
                        if (isset($affected[$started])) {
                            $this->shutdownOne($started);
                        }
                    }
                }
            }
        }
    }

    /** @return list<Error> Resources are released in reverse dependency order. */
    public function shutdown(): array
    {
        $errors = [];
        foreach (array_reverse(array_keys($this->started)) as $name) {
            $error = $this->shutdownOne($name);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        return $errors;
    }

    private function shutdownOne(string $name): ?Error
    {
        unset($this->started[$name]);
        try {
            $this->modules[$name]->shutdown($this->contexts[$name]);
            return null;
        } catch (\Throwable $exception) {
            $this->health[$name]['errors'][] = 'Module shutdown hook failed.';
            $this->logger->log('error', 'Module shutdown failed', ['module' => $name, 'exception' => $exception::class]);
            return new Error('MODULE_SHUTDOWN_FAILED', 'Module cleanup failed: ' . $name);
        }
    }
}
