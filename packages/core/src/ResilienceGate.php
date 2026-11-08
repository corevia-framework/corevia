<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Shared state belongs in an adapter. Failed-open state must never authorize an outbound call. */
interface ResilienceGate
{
    public function enter():CircuitPermit;
    public function succeed(CircuitPermit $permit):void;
    public function fail(CircuitPermit $permit):void;
    public function takeRetry():bool;
}
