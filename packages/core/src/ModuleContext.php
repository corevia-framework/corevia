<?php

declare(strict_types=1);

namespace Corevia\Core;

final class ModuleContext
{
    public array $registeredActions = [];
    public array $registeredFlows = [];
    public array $handlers = [];
    private bool $frozen = false;

    public function __construct(public readonly string $module, public readonly array $config = [], public readonly ?Container $container = null, public readonly ?ModuleManifest $manifest = null)
    {
    }

    /** @param array<string, class-string<Action>> $actions */
    public function actions(array $actions): void
    {
        $this->assertRegistering();
        foreach ($actions as $name => $class) {
            if (!is_subclass_of($class, Action::class)) {
                throw new \InvalidArgumentException('Invalid action: ' . $class);
            }
            $this->registeredActions[$name] = $class;
        }
    }

    /** @param list<Flow> $flows */
    public function flows(array $flows): void
    {
        $this->assertRegistering();
        foreach ($flows as $flow) {
            $this->registeredFlows[$flow->name] = $flow;
        }
    }

    public function on(string $event, callable $handler): void
    {
        $this->assertRegistering();
        $this->handlers[] = [$event, $handler];
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function assertRegistering(): void
    {
        if ($this->frozen) {
            throw new \LogicException('Register actions, flows and listeners in register(), before module boot.');
        }
    }
}
