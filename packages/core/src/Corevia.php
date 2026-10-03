<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Corevia
{
    public readonly Container $container;
    public readonly ModuleRegistry $registry;
    public readonly EventBus $events;
    private array $actions = [];
    private array $flows = [];
    private ?FlowEngine $engine = null;

    private function __construct(public readonly string $basePath, public readonly RunStore $store, public readonly Logger $logger)
    {
        $this->container = new Container();
        $this->registry = new ModuleRegistry($logger);
        $this->events = new LocalEventBus($logger);
        $this->container->set(EventBus::class, $this->events);
        $this->container->set(Logger::class, $logger);
    }

    public static function create(string $basePath, ?RunStore $store = null, ?Logger $logger = null): self
    {
        return new self($basePath, $store ?? new MemoryRunStore(), $logger ?? new JsonLogger());
    }

    /** @param list<Module|class-string<Module>> $modules */
    public function modules(array $modules, array $disabled = []): self
    {
        foreach ($modules as $module) {
            $instance = is_string($module) ? $this->container->get($module) : $module;
            $this->registry->add($instance, !in_array($instance->name(), $disabled, true));
        }
        return $this;
    }

    public function boot(array $config = []): void
    {
        if ($this->engine !== null) {
            return;
        }
        $this->registry->boot($config);
        foreach ($this->registry->contexts() as $context) {
            foreach ($context->registeredActions as $name => $class) {
                $this->actions[$name] = ['class' => $class, 'module' => $context->module];
            }
            foreach ($context->registeredFlows as $name => $flow) {
                $this->flows[$name] = $flow;
            }
            foreach ($context->handlers as [$event, $handler]) {
                $this->events->on($event, $handler);
            }
        }
        $this->engine = new FlowEngine(new ActionRunner($this->container), $this->store, $this->events, $this->logger, $this->actions);
    }

    public function flows(): array
    {
        return $this->flows;
    }

    public function flow(string $name): Flow
    {
        return $this->flows[$name] ?? throw new \OutOfBoundsException('Flow not found.');
    }

    public function run(string $name, array $input, ?string $key = null, ?string $traceId = null, ?string $requestId = null): FlowResult
    {
        $flow = $this->flow($name);
        return ($this->engine ?? throw new \LogicException('Boot first.'))->run($flow, new ($flow->stateClass)($input), $key, $traceId, $requestId);
    }

    public function retry(string $runId, int $step): FlowResult
    {
        $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
        return ($this->engine ?? throw new \LogicException('Boot first.'))->retry($this->flow($run['flow_name']), $runId, $step);
    }

    public function call(string $name, array $input, string $traceId, string $requestId): ActionResult
    {
        $entry = $this->actions[$name] ?? null;
        if ($entry === null) {
            return ActionResult::failure('ACTION_NOT_FOUND', 'Action not found.');
        }
        return (new ActionRunner($this->container))->run($entry['class'], new State($input), new ExecutionContext($requestId, $traceId, 'action.call', $entry['module'], $this->events, $this->logger, $requestId));
    }
}
