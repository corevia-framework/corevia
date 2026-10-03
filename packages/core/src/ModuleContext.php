<?php

declare(strict_types=1);

namespace Corevia\Core;

final class ModuleContext
{
    public array $registeredActions = [];
    public array $registeredFlows = [];
    public array $handlers = [];

    public function __construct(public readonly string $module, public readonly array $config = [])
    {
    }

    /** @param array<string, class-string<Action>> $actions */
    public function actions(array $actions): void
    {
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
        foreach ($flows as $flow) {
            $this->registeredFlows[$flow->name] = $flow;
        }
    }

    public function on(string $event, callable $handler): void
    {
        $this->handlers[] = [$event, $handler];
    }
}
