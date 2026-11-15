<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Cooperative monotonic deadline; blocking adapters still need their own I/O timeouts. */
final class Deadline
{
    private int $end;
    private bool $cancelled=false;
    private \Closure $clock;
    public function __construct(int $milliseconds,?callable $nanoseconds=null)
    {
        if($milliseconds<1||$milliseconds>3600000){throw new \InvalidArgumentException('Deadline must be 1ms..1h.');}
        $this->clock=\Closure::fromCallable($nanoseconds??static fn():int=>hrtime(true));$this->end=($this->clock)()+$milliseconds*1000000;
    }
    public function cancel():void{$this->cancelled=true;}
    public function check():void{if($this->cancelled||($this->clock)()>=$this->end){throw new \RuntimeException($this->cancelled?'WORK_CANCELLED':'DEADLINE_EXCEEDED');}}
    public function remainingMilliseconds():int{$this->check();return max(1,(int)ceil(($this->end-($this->clock)())/1000000));}
}
