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
    private array $actions = [];
    private array $flows = [];
    private ?FlowEngine $engine = null;

    private function __construct(public readonly string $basePath, public readonly RunStore $store, public readonly Logger $logger, ?ConfigRepository $config = null, ?Capabilities $capabilities = null)
    {
        $this->config = $config ?? new ConfigRepository();
        $this->capabilities = $capabilities ?? new Capabilities();
        $this->capabilities->define('core.runtime', metadata: ['version' => '2.0.0-alpha.2']);
        $this->capabilities->define('core.flows');
        $this->capabilities->define('core.events');
        $this->capabilities->define('core.run_store', metadata: ['adapter' => $store::class]);
        $this->kernel = new Kernel($logger);
        $this->container = new Container();
        $this->registry = new ModuleRegistry($logger);
        $this->events = new LocalEventBus($logger);
        $this->container->set(EventBus::class, $this->events);
        $this->container->set(Logger::class, $logger);
        $this->container->set(RunStore::class, $store);
        $this->container->set(ConfigRepository::class, $this->config);
        $this->container->set(Capabilities::class, $this->capabilities);
        $this->container->set(Kernel::class, $this->kernel);
        $this->container->set(self::class, $this);
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
        return $this->kernel->shutdown(fn (): array => $this->registry->shutdown());
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
        $flow = $this->flow($name);
        return ($this->engine ?? throw new \LogicException('Boot first.'))->run($flow, new ($flow->stateClass)($input), $key, $traceId, $requestId);
    }

    public function retry(string $runId, int $step): FlowResult
    {
        $this->kernel->assertReady();
        $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
        return ($this->engine ?? throw new \LogicException('Boot first.'))->retry($this->flow($run['flow_name']), $runId, $step);
    }

    public function resume(string $runId, ?string $event = null): FlowResult
    {
        $this->kernel->assertReady();
        $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
        return $this->engine->resume($this->flow($run['flow_name']), $runId, $event);
    }

    public function call(string $name, array $input, string $traceId, string $requestId): ActionResult
    {
        $this->kernel->assertReady();
        $entry = $this->actions[$name] ?? null;
        if ($entry === null) {
            return ActionResult::failure('ACTION_NOT_FOUND', 'Action not found.');
        }
        return (new ActionRunner($this->container))->run($entry['class'], new State($input), new ExecutionContext($requestId, $traceId, 'action.call', $entry['module'], $this->events, $this->logger, $requestId));
    }
}
