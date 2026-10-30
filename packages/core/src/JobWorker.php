<?php
declare(strict_types=1);
namespace Corevia\Core;
final readonly class JobWorker
{
    public function __construct(private WorkQueue $queue,private ScopeManager $scopes,private array $handlers,private int $leaseSeconds=120){if($leaseSeconds<10||$leaseSeconds>3600){throw new \InvalidArgumentException('Worker lease must be 10..3600 seconds.');}}
    public function once(?int $now=null):array
    {
        $now??=$this->queue->clock();$claim=$this->queue->claim($now,$this->leaseSeconds);if($claim===null){return ['status'=>'empty'];}$handler=$this->handlers[$claim->task]??null;
        if(!is_callable($handler)){$this->queue->fail($claim,$now,true);return ['status'=>'unknown_task','id'=>$claim->id];}
        try{$result=$this->scopes->run('job',fn(ExecutionScope $scope)=>$handler($claim->input(),$claim,$scope),['job_id'=>$claim->id,'attempt'=>$claim->attempt]);if(!is_array($result)){throw new \LogicException('Job handlers return an array.');}$ok=$this->queue->complete($claim,$result,$this->queue->clock());return ['status'=>$ok?'completed':'lease_lost','id'=>$claim->id];}
        catch(\Throwable){$ok=$this->queue->fail($claim,$this->queue->clock());return ['status'=>$ok?'failed':'lease_lost','id'=>$claim->id];}
    }
}
