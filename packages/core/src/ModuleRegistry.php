<?php

declare(strict_types=1);

namespace Corevia\Core;

final class ModuleRegistry
{
    private array $modules = [];
    private array $health = [];
    private array $contexts = [];

    public function __construct(private readonly Logger $logger)
    {
    }

    public function add(Module $module, bool $enabled = true): void
    {
        $name = $module->name();
        if (isset($this->modules[$name])) {
            throw new \InvalidArgumentException('Duplicate module: ' . $name);
        }
        $this->modules[$name] = $module;
        $this->health[$name] = ['status' => $enabled ? 'pending' : 'disabled', 'errors' => []];
    }

    public function boot(array $config = []): void
    {
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
            foreach ($this->modules[$name]->dependencies() as $dependency) {
                if (!$this->bootOne($dependency, [...$stack, $name], $config)) {
                    throw new \RuntimeException('Unavailable dependency: ' . $dependency);
                }
            }
            $context = new ModuleContext($name, $config[$name] ?? []);
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
        return $this->contexts;
    }
}
