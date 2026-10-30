<?php
declare(strict_types=1);
namespace Corevia\Core;
interface WorkQueue
{
    public function clock():int;
    public function dispatch(string $task,array $input,bool $idempotent,int $now):string;
    public function claim(int $now,int $seconds=60):?JobClaim;
    public function renew(JobClaim $claim,int $now,int $seconds=60):bool;
    public function complete(JobClaim $claim,array $result,int $now):bool;
    public function fail(JobClaim $claim,int $now,bool $permanent=false):bool;
}
