<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Flow
{
    /** @var list<Step> */
    public array $steps = [];

    private function __construct(public readonly string $name, public readonly string $stateClass)
    {
        if (!is_a($stateClass, State::class, true)) {
            throw new \InvalidArgumentException('Flow requires a State type.');
        }
    }

    public static function define(string $name, string $stateClass = State::class): self
    {
        return new self($name, $stateClass);
    }

    public function step(string $action): self
    {
        $this->steps[] = new Step($action);
        return $this;
    }

    private function current(): Step
    {
        return $this->steps[array_key_last($this->steps)] ?? throw new \LogicException('Add a step first.');
    }

    public function critical(): self
    {
        $this->current()->isCritical = true;
        return $this;
    }

    public function nonCritical(): self
    {
        $this->current()->isCritical = false;
        return $this;
    }

    /** Retry count excludes the initial attempt. Delays are bounded to 30 seconds. */
    public function retry(int $count, int $delayMs = 0, bool $exponential = false): self
    {
        if ($count < 0 || $count > 100 || $delayMs < 0 || $delayMs > 30000) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
        $step = $this->current();
        $step->retries = $count;
        $step->delayMs = $delayMs;
        $step->exponential = $exponential;
        return $this;
    }

    public function when(callable $condition): self
    {
        $this->current()->condition = \Closure::fromCallable($condition);
        return $this;
    }

    /** Max attempts includes the first call; retry() still counts extra calls. */
    public function retryPolicy(int $maxAttempts, int $backoffMs = 0, bool $jitter = true, array $exceptions = []): self
    {
        if ($maxAttempts < 1) { throw new \InvalidArgumentException('At least one attempt is required.'); }
        foreach ($exceptions as $class) {
            if (!is_string($class) || !is_a($class, \Throwable::class, true)) { throw new \InvalidArgumentException('Expected exception class.'); }
        }
        $this->retry($maxAttempts - 1, $backoffMs, true);
        $this->current()->jitter = $jitter;
        $this->current()->retryableExceptions = $exceptions;
        return $this;
    }

    public function timeout(int $milliseconds): self
    {
        if ($milliseconds < 1 || $milliseconds > 300000) { throw new \InvalidArgumentException('Timeout must be 1..300000ms.'); }
        $this->current()->timeoutMs = $milliseconds;
        return $this;
    }

    public function compensate(string $action): self { $this->current()->compensation = $action; return $this; }

    /** Required top-level state keys, checked before committing action state. */
    public function outputs(array $keys): self
    {
        foreach ($keys as $key) { if (!is_string($key) || $key === '') { throw new \InvalidArgumentException('Output keys must be strings.'); } }
        $this->current()->outputKeys = $keys;
        return $this;
    }

    public function pause(string $checkpoint, ?string $event = null): self
    {
        if ($checkpoint === '' || strlen($checkpoint) > 128 || ($event !== null && ($event === '' || strlen($event) > 128))) { throw new \InvalidArgumentException('Invalid checkpoint.'); }
        $step = new Step('@checkpoint'); $step->checkpoint = $checkpoint; $step->waitEvent = $event; $this->steps[] = $step;
        return $this;
    }

    public function waitFor(string $event): self { return $this->pause($event, $event); }
    public function approval(string $name): self { return $this->pause('approval:' . $name); }

    /** Requires an optional executor with isolated, ordered results. */
    public function parallel(array $actions): self
    {
        if ($actions === []) { throw new \InvalidArgumentException('Parallel group is empty.'); }
        foreach ($actions as $action) { if (!is_string($action) || $action === '') { throw new \InvalidArgumentException('Invalid parallel action.'); } }
        $step = new Step('@parallel'); $step->parallelActions = array_values($actions); $this->steps[] = $step;
        return $this;
    }
}
