<?php
declare(strict_types=1);
namespace Corevia\Testing;
final class Clock
{
    public function __construct(private int $now=1700000000){Guard::development();}
    public function __invoke():int{return $this->now;}
    public function advance(int $seconds):void{if($seconds<0||$seconds>31536000){throw new \InvalidArgumentException('Advance by 0..31536000 seconds.');}$this->now+=$seconds;}
}
