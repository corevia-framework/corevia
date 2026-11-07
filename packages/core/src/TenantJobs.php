<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Dispatch-only application API; workers resolve current authority before invoking a handler. */
final readonly class TenantJobs
{
    public function __construct(private WorkQueue $queue,private string $key)
    {
        if(strlen($key)<32){throw new \InvalidArgumentException('Use a private, independent job signing key of at least 32 bytes.');}
    }
    public function dispatch(TenantScope $scope,string $task,array $input,bool $idempotent,int $lifetime=3600):string
    {
        $scope->check();if(!$idempotent){throw new \LogicException('Tenant handlers must explicitly promise retry safety.');}
        if(!preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$task)||$lifetime<1||$lifetime>86400){throw new \InvalidArgumentException('Invalid tenant task/lifetime.');}
        $envelope=['version'=>1,'tenant'=>$scope->tenant,'actor'=>$scope->actor,'task'=>$task,'input'=>$input,'expires'=>$this->queue->clock()+$lifetime,'nonce'=>bin2hex(random_bytes(16))];
        $wire=json_encode($envelope,JSON_THROW_ON_ERROR);if(strlen($wire)>65536){throw new \LengthException('Tenant job exceeds 64 KiB.');}
        return $this->queue->dispatch('corevia.tenant',['envelope'=>$wire,'signature'=>hash_hmac('sha256',$wire,$this->key)],true,$this->queue->clock());
    }
    public function execute(JobClaim $claim,callable $resolve,callable $handler):mixed
    {
        $input=$claim->input();$wire=$input['envelope']??null;$signature=$input['signature']??null;
        if($claim->task!=='corevia.tenant'||!is_string($wire)||strlen($wire)>65536||!is_string($signature)||!hash_equals(hash_hmac('sha256',$wire,$this->key),$signature)){throw new \RuntimeException('Invalid signed tenant job.');}
        $data=json_decode($wire,true,32,JSON_THROW_ON_ERROR);
        if(($data['version']??null)!==1||!is_int($data['expires']??null)||$data['expires']<=$this->queue->clock()){throw new \RuntimeException('Tenant job expired.');}
        $scope=$resolve($data['actor'],$data['tenant'],$data['task']);
        if(!$scope instanceof TenantScope||$scope->actor!==$data['actor']||$scope->tenant!==$data['tenant']){throw new \RuntimeException('Tenant job authority mismatch.');}
        $scope->check();return $handler($scope,$data['task'],$data['input'],'tenant-job:'.$data['nonce']);
    }
}
