<?php
declare(strict_types=1);
namespace Corevia\Ai;
/** Trusted application code only; this adapter is not a sandbox. */
final readonly class CallableTool implements ToolHandler
{
    private \Closure $handler;
    public function __construct(callable $handler) { $this->handler=\Closure::fromCallable($handler); }
    public function execute(mixed $input,ToolExecution $execution):mixed { return ($this->handler)($input,$execution); }
}
