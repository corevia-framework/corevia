<?php
declare(strict_types=1);
namespace Corevia\Core;

final class ExecutionScope
{
    private array $instances = [];
    private array $cleanup = [];
    private string $state = 'active';
    public readonly string $id;
    public function __construct(public readonly string $kind, public readonly array $metadata = [], public readonly ?string $parentId = null)
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $kind)) { throw new \InvalidArgumentException('Invalid scope kind.'); }
        new ConfigRepository($metadata); $this->id = bin2hex(random_bytes(16));
    }
    public function active(): bool { return $this->state === 'active'; }
    public function onClose(callable $work): void { $this->assertActive(); $this->cleanup[] = \Closure::fromCallable($work); }
    public function remember(string $id, callable $factory, ?callable $dispose = null): object
    {
        $this->assertActive();
        if (isset($this->instances[$id])) { return $this->instances[$id]; }
        $value = $factory();
        if (!is_object($value)) { throw new \LogicException('Scoped factory must return an object.'); }
        $this->instances[$id] = $value;
        if ($dispose !== null) { $this->onClose(static fn () => $dispose($value)); }
        elseif ($value instanceof Disposable) { $this->onClose($value->dispose(...)); }
        return $value;
    }
    /** @return list<\Throwable> Every cleanup runs once, in reverse acquisition order. */
    public function close(): array
    {
        if ($this->state === 'closed') { return []; }
        $this->assertActive(); $this->state = 'closing'; $errors = [];
        try { foreach (array_reverse($this->cleanup) as $work) { try { $work($this); } catch (\Throwable $error) { $errors[] = $error; } } }
        finally { $this->instances = []; $this->cleanup = []; $this->state = 'closed'; }
        return $errors;
    }
    private function assertActive(): void { if (!$this->active()) { throw new \LogicException('Execution scope is no longer active.'); } }
}
