<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\TenantScope;
use Corevia\Persistence\SqlUsageBudget;

/** Wrap before AiClient. Charge UTF-8 JSON input bytes plus requested output units up front. */
final readonly class BudgetedProvider implements AiProvider
{
    private \Closure $clock;
    public function __construct(private AiProvider $provider,private TenantScope $scope,private SqlUsageBudget $budget,?callable $clock=null){$this->clock=\Closure::fromCallable($clock??time(...));}
    public function stream(array $messages,int $maxTokens):iterable
    {
        if($maxTokens<1||$maxTokens>8192||!array_is_list($messages)||count($messages)>64){throw new \InvalidArgumentException('Invalid budgeted inference bounds.');}
        $bytes=strlen(json_encode($messages,JSON_THROW_ON_ERROR));if($bytes>65536){throw new \LengthException('Input exceeds budgeted context bound.');}
        $this->budget->reserve($this->scope,$bytes+$maxTokens,($this->clock)());
        foreach($this->provider->stream($messages,$maxTokens) as $delta){$this->scope->check();yield $delta;}
    }
}
