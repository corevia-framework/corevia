<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Application-owned mapping. Never infer ownership by scanning arbitrary tables or directories. */
interface AccountDataAdapter
{
    public function name():string;
    public function revision():string;
    public function export(string $userId):array;
    /** Throw DataRetentionHold for a legal/business hold before any erasure starts. */
    public function assertDeletable(string $userId):void;
    /** Must be bounded and idempotent by operation ID; recheck holds at the storage commit. */
    public function erase(string $userId,string $operationId):void;
}
