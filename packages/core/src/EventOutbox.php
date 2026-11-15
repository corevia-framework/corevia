<?php
declare(strict_types=1);
namespace Corevia\Core;

interface EventOutbox
{
    public function commit(string $topic,array $event,callable $applicationWrite,int $now):array;
    public function relay(callable $send,int $now,int $leaseSeconds=60,int $maxAttempts=6):bool;
    public function status():array;
    public function history(string $eventId,int $limit=50):array;
    public function retryDead(string $eventId,int $expectedAttempt,string $requestId,int $now):bool;
}
