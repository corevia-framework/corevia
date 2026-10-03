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
}
