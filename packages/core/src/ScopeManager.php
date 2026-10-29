<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Fiber-local execution scopes; shared process services remain intentionally shared. */
final class ScopeManager
{
    private array $main = [];
    private \WeakMap $fibers;
    private int $active = 0;
    public function __construct(private readonly ?Logger $logger = null) { $this->fibers = new \WeakMap(); }
    private function stack(): array { $fiber = \Fiber::getCurrent(); return $fiber === null ? $this->main : ($this->fibers[$fiber] ?? []); }
    private function replace(array $stack): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) { $this->main = $stack; }
        elseif ($stack === []) { unset($this->fibers[$fiber]); }
        else { $this->fibers[$fiber] = $stack; }
    }
    public function current(): ?ExecutionScope { $stack = $this->stack(); return $stack === [] ? null : $stack[array_key_last($stack)]; }
    public function activeCount(): int { return $this->active; }
    public function run(string $kind, callable $work, array $metadata = []): mixed
    {
        $stack = $this->stack(); $scope = new ExecutionScope($kind, $metadata, $this->current()?->id);
        $this->replace([...$stack, $scope]); $this->active++; $start = hrtime(true); $failure = null;
        try { return $work($scope); }
        catch (\Throwable $error) { $failure = $error; throw $error; }
        finally {
            try { $errors = $scope->close(); }
            finally { $this->replace($stack); $this->active--; }
            if ($errors !== []) {
                try { $this->logger?->log('error', 'Execution cleanup failed', ['scope_id' => $scope->id, 'scope_kind' => $kind, 'duration_ms' => (hrtime(true) - $start) / 1e6, 'exception_classes' => array_map(static fn (\Throwable $error): string => $error::class, $errors)]); } catch (\Throwable) { /* Preserve execution/cleanup failures if logging is unavailable. */ }
                if ($failure === null) { throw new \RuntimeException('SCOPE_CLEANUP_FAILED'); }
            }
        }
    }
}
