<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\{ActionContract,CanonicalJson,Deadline,ScopeManager,TenantScope,ToolApprovals,WorkContext};

/** Request-scoped broker. Models supply names/JSON arguments; they cannot register handlers or approve calls. */
final class ToolBroker
{
    private array $tools=[];
    private int $executed=0;
    private \Closure $clock;
    public function __construct(private readonly ActionContract $contract,private readonly ToolApprovals $approvals,private readonly ScopeManager $scopes,
        array $tools,private readonly int $maximumCalls=8,?callable $clock=null)
    {
        if (count($tools)>64||$maximumCalls<1||$maximumCalls>16) { throw new \InvalidArgumentException('Invalid tool run bounds.'); }
        foreach ($tools as $tool) { if (!$tool instanceof ToolDefinition||isset($this->tools[$tool->name])) { throw new \InvalidArgumentException('Register unique explicit tool definitions.'); }$contract->inputSchema($tool->action);$contract->outputSchema($tool->action);$this->tools[$tool->name]=$tool; }
        $this->clock=\Closure::fromCallable($clock??time(...));
    }
    private function plan(TenantScope $scope,string $name,mixed $arguments):array
    {
        $tool=$this->tools[$name]??throw new \RuntimeException('TOOL_NOT_REGISTERED');$scope->requireAbility($tool->ability);
        $json=CanonicalJson::encode($arguments);if (strlen($json)>16384) { throw new \LengthException('Tool input exceeds 16 KiB.'); }
        $input=json_decode($json,false,40,JSON_THROW_ON_ERROR);$this->contract->assertInput($tool->action,$input);
        $hash=CanonicalJson::hash([$scope->tenant,$scope->actor,$name,$tool->ability,$tool->action,$tool->revision,$tool->requiresApproval,$tool->timeoutMs,
            $this->contract->inputSchema($tool->action),$this->contract->outputSchema($tool->action),$input]);return [$tool,$input,$hash];
    }
    public function propose(TenantScope $scope,string $name,mixed $arguments):array
    {
        [$tool,$input,$hash]=$this->plan($scope,$name,$arguments);$scope->requireAbility('ai.tools.request');
        $request=$this->approvals->propose($scope,$hash,$tool->requiresApproval,($this->clock)());
        return ['request_id'=>$request,'tool'=>$name,'fingerprint'=>$hash,'requires_approval'=>$tool->requiresApproval,'arguments'=>$input];
    }
    public function execute(TenantScope $scope,string $name,mixed $arguments,string $requestId):mixed
    {
        [$tool,$input,$hash]=$this->plan($scope,$name,$arguments);$scope->requireAbility('ai.tools.execute');WorkContext::current()?->check();
        if ($this->executed>=$this->maximumCalls) { throw new \RuntimeException('TOOL_CALL_BUDGET_EXHAUSTED'); }
        if (!$this->approvals->consume($scope,$requestId,$hash,($this->clock)())) { throw new \RuntimeException('TOOL_APPROVAL_REQUIRED_OR_CONSUMED'); }
        $this->executed++;
        return WorkContext::run(new Deadline($tool->timeoutMs),fn($context)=>$this->scopes->run('ai.tool',function()use($scope,$requestId,$context,$tool,$input):mixed {
            $execution=new ToolExecution($scope,'tool:'.$requestId,$context);$execution->check();$result=$tool->handler->execute($input,$execution);$execution->check();
            $json=CanonicalJson::encode($result);if (strlen($json)>65536) { throw new \LengthException('Tool output exceeds 64 KiB.'); }
            $result=json_decode($json,false,40,JSON_THROW_ON_ERROR);$this->contract->assertOutput($tool->action,$result);return $result;
        },['tool'=>$name,'request_id'=>$requestId]));
    }
}
