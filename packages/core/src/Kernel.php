<?php

declare(strict_types=1);

namespace Corevia\Core;

/** Explicit lifecycle coordinator; adapters decide when to call shutdown. */
final class Kernel
{
    private LifecycleState $state = LifecycleState::Created;
    private array $timeline = [];
    private array $readyHooks = [];
    private array $shutdownHooks = [];

    public function __construct(private readonly Logger $logger)
    {
        $this->transition(LifecycleState::Created);
    }

    public function state(): LifecycleState
    {
        return $this->state;
    }

    public function timeline(): array
    {
        return $this->timeline;
    }

    public function assertConfigurable(): void
    {
        if ($this->state !== LifecycleState::Created) {
            throw new \LogicException('Configure the application before boot.');
        }
    }

    public function assertReady(): void
    {
        if ($this->state !== LifecycleState::Ready) {
            throw new \LogicException('Application is not ready: ' . $this->state->value);
        }
    }

    public function onReady(callable $hook): void
    {
        $this->assertConfigurable();
        $this->readyHooks[] = \Closure::fromCallable($hook);
    }

    public function onShutdown(callable $hook): void
    {
        $this->assertConfigurable();
        $this->shutdownHooks[] = \Closure::fromCallable($hook);
    }

    public function boot(callable $register, callable $boot): void
    {
        if ($this->state === LifecycleState::Ready) {
            return;
        }
        $this->assertConfigurable();
        try {
            $this->transition(LifecycleState::Registering);
            $register();
            $this->transition(LifecycleState::Booting);
            $boot();
            $this->transition(LifecycleState::Ready);
            foreach ($this->readyHooks as $hook) {
                $hook();
            }
        } catch (\Throwable $exception) {
            $this->transition(LifecycleState::Failed);
            $this->logger->log('error', 'Kernel boot failed', ['exception' => $exception::class]);
            throw $exception;
        }
    }

    /** @return list<Error> Cleanup failures are reported and do not skip other cleanup hooks. */
    public function shutdown(callable $cleanup): array
    {
        if ($this->state === LifecycleState::Shutdown) {
            return [];
        }
        if (in_array($this->state, [LifecycleState::Registering, LifecycleState::Booting, LifecycleState::ShuttingDown], true)) {
            throw new \LogicException('Cannot shut down during a lifecycle transition.');
        }
        $this->transition(LifecycleState::ShuttingDown);
        $errors = [];
        try {
            foreach (array_reverse($this->shutdownHooks) as $hook) {
                try {
                    $hook();
                } catch (\Throwable $exception) {
                    $errors[] = new Error('SHUTDOWN_HOOK_FAILED', 'Application shutdown hook failed.');
                    $this->logger->log('error', 'Shutdown hook failed', ['exception' => $exception::class]);
                }
            }
            try {
                $errors = [...$errors, ...$cleanup()];
            } catch (\Throwable $exception) {
                $errors[] = new Error('SHUTDOWN_FAILED', 'Application cleanup failed.');
                $this->logger->log('error', 'Application cleanup failed', ['exception' => $exception::class]);
            }
        } finally {
            $this->transition(LifecycleState::Shutdown);
        }
        return $errors;
    }

    private function transition(LifecycleState $state): void
    {
        $this->state = $state;
        $this->timeline[] = ['state' => $state->value, 'at' => gmdate('c'), 'monotonic_ns' => hrtime(true)];
    }
}
