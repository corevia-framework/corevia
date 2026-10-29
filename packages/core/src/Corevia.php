<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Corevia
{
    public readonly Container $container;
    public readonly ModuleRegistry $registry;
    public readonly EventBus $events;
    public readonly Kernel $kernel;
    public readonly ConfigRepository $config;
    public readonly Capabilities $capabilities;
    public readonly ScopeManager $scopes;
    private array $actions = [];
    private array $flows = [];
    private ?FlowEngine $engine = null;

    private function __construct(public readonly string $basePath, public readonly RunStore $store, public readonly Logger $logger, ?ConfigRepository $config = null, ?Capabilities $capabilities = null)
    {
        $this->config = $config ?? new ConfigRepository();
        $this->capabilities = $capabilities ?? new Capabilities();
        $this->capabilities->define('core.runtime', metadata: ['version' => Version::VERSION]);
        $this->capabilities->define('core.flows');
        $this->capabilities->define('core.events');
        $this->capabilities->define('core.run_store', metadata: ['adapter' => $store::class]);
        $this->kernel = new Kernel($logger);
        $this->scopes = new ScopeManager($logger);
        $this->container = new Container($this->scopes);
        $this->registry = new ModuleRegistry($logger);
        $this->events = new LocalEventBus($logger);
        $this->container->set(EventBus::class, $this->events);
        $this->container->set(Logger::class, $logger);
        $this->container->set(RunStore::class, $store);
        $this->container->set(ConfigRepository::class, $this->config);
        $this->container->set(Capabilities::class, $this->capabilities);
        $this->container->set(Kernel::class, $this->kernel);
        $this->container->set(self::class, $this);
        $this->container->set(ScopeManager::class, $this->scopes);
        $this->container->scoped(ExecutionScope::class, fn (): ExecutionScope => $this->scopes->current() ?? throw new \LogicException('No execution scope is active.'));
    }

    public static function create(string $basePath, ?RunStore $store = null, ?Logger $logger = null, ?ConfigRepository $config = null, ?Capabilities $capabilities = null): self
    {
        return new self($basePath, $store ?? new MemoryRunStore(), $logger ?? new JsonLogger(), $config, $capabilities);
    }

    /** @param list<Module|class-string<Module>> $modules */
    public function modules(array $modules, array $disabled = []): self
    {
        $this->kernel->assertConfigurable();
        foreach ($modules as $module) {
            $instance = is_string($module) ? $this->container->get($module) : $module;
            $this->registry->add($instance, !in_array($instance->name(), $disabled, true));
        }
        return $this;
    }

    /** V3 canonical ordering; V2 modules() preserves declared registration order. */
    public function compose(array $modules, array $disabled = []): self
    {
        $this->kernel->assertConfigurable();
        $this->registry->canonical();
        return $this->modules($modules, $disabled);
    }

    public function graph(): array
    {
        $this->kernel->assertReady();
        $plan = $this->registry->plan() ?? throw new \LogicException('Use compose() for a compiled application graph.');
        $modules = [];
        foreach ($this->registry->contexts() as $id => $context) {
            $actions = $context->registeredActions; ksort($actions, SORT_STRING); $flows = [];
            foreach ($context->registeredFlows as $name => $flow) {
                $steps = [];
                foreach ($flow->steps as $step) { $data = get_object_vars($step); $data['condition'] = $step->condition !== null; $steps[] = $data; }
                $flows[$name] = ['state' => $flow->stateClass, 'steps' => $steps];
            }
            ksort($flows, SORT_STRING);
            $events = array_map(static fn (array $handler): string => $handler[0], $context->handlers); sort($events, SORT_STRING);
            $modules[$id] = ['actions' => $actions, 'flows' => $flows, 'listeners' => $events];
        }
        return DeclarationIdentity::normalize(['format' => 1, 'plan' => $plan->jsonSerialize(), 'registrations' => $modules, 'bindings' => $this->container->definitions(), 'health' => $this->registry->health(), 'capabilities' => $this->capabilities->all()]);
    }

    public function effectiveConfiguration(): array
    {
        $this->kernel->assertReady(); $modules = [];
        foreach ($this->registry->contexts() as $id => $context) { $modules[$id] = $context->config; }
        return ['application' => $this->config->all(), 'modules' => $modules];
    }

    public function boot(array $config = []): void
    {
        $this->kernel->boot(function () use ($config): void {
            $modules = $this->config->get('modules', []);
            if (!is_array($modules)) {
                throw new \InvalidArgumentException('Module configuration must be an array.');
            }
            $this->config->set('modules', ConfigRepository::merge($modules, $config));
            $this->registry->boot($this->config->get('modules'), $this->container, $this->capabilities);
            $this->config->freeze();
        }, function (): void {
            $this->registry->activate();
            $this->assembleRuntime();
        });
    }

    public function onReady(callable $hook): self
    {
        $this->kernel->onReady(fn () => $hook($this));
        return $this;
    }

    public function onShutdown(callable $hook): self
    {
        $this->kernel->onShutdown(fn () => $hook($this));
        return $this;
    }

    public function shutdown(): array
    {
        if ($this->scopes->activeCount() > 0) { throw new \LogicException('Finish execution scopes before shutting down.'); }
        return $this->kernel->shutdown(fn (): array => $this->registry->shutdown());
    }

    public function scope(string $kind, callable $work, array $metadata = []): mixed
    {
        $this->kernel->assertReady(); return $this->scopes->run($kind, $work, $metadata);
    }

    private function assembleRuntime(): void
    {
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
        $this->engine = new FlowEngine(new ActionRunner($this->container), $this->store, $this->events, $this->logger, $this->actions, $this->container->has(ParallelExecutor::class) ? $this->container->get(ParallelExecutor::class) : null);
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
        $this->kernel->assertReady();
        if ($this->scopes->current() === null) { return $this->scope('flow', fn (): FlowResult => $this->run($name, $input, $key, $traceId, $requestId)); }
        $flow = $this->flow($name);
        return ($this->engine ?? throw new \LogicException('Boot first.'))->run($flow, new ($flow->stateClass)($input), $key, $traceId, $requestId);
    }

    public function retry(string $runId, int $step): FlowResult
    {
        $this->kernel->assertReady();
        if ($this->scopes->current() === null) { return $this->scope('retry', fn (): FlowResult => $this->retry($runId, $step)); }
        $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
        return ($this->engine ?? throw new \LogicException('Boot first.'))->retry($this->flow($run['flow_name']), $runId, $step);
    }

    public function resume(string $runId, ?string $event = null): FlowResult
    {
        $this->kernel->assertReady();
        if ($this->scopes->current() === null) { return $this->scope('resume', fn (): FlowResult => $this->resume($runId, $event)); }
        $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
        return $this->engine->resume($this->flow($run['flow_name']), $runId, $event);
    }

    public function call(string $name, array $input, string $traceId, string $requestId): ActionResult
    {
        $this->kernel->assertReady();
        if ($this->scopes->current() === null) { return $this->scope('action', fn (): ActionResult => $this->call($name, $input, $traceId, $requestId)); }
        $entry = $this->actions[$name] ?? null;
        if ($entry === null) {
            return ActionResult::failure('ACTION_NOT_FOUND', 'Action not found.');
        }
        return (new ActionRunner($this->container))->run($entry['class'], new State($input), new ExecutionContext($requestId, $traceId, 'action.call', $entry['module'], $this->events, $this->logger, $requestId));
    }
}
