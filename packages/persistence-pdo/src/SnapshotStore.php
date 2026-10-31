<?php
declare(strict_types=1);
namespace Corevia\Persistence;
/** Committed snapshots and linearizable compare/exchange; version zero means absent. */
interface SnapshotStore
{
    public function read():array;
    public function compareExchange(int $version,string $payload):bool;
}
