<?php
declare(strict_types=1);
namespace Corevia\Ai;
final readonly class ToolDefinition
{
    public function __construct(public string $name,public string $ability,public string $action,public string $revision,
        public ToolHandler $handler,public bool $requiresApproval=true,public int $timeoutMs=5000)
    {
        foreach ([$name,$action,$revision] as $value) { if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D',$value)) { throw new \InvalidArgumentException('Pin static tool names/actions/revisions.'); } }
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$ability)||$timeoutMs<1||$timeoutMs>30000) { throw new \InvalidArgumentException('Use an explicit tool capability and 1..30000 ms budget.'); }
    }
}
