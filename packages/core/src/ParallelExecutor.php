<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Optional infrastructure adapter. Tasks must run in isolation; return results in input order. */
interface ParallelExecutor
{
    /** @param list<callable(): array> $tasks @return list<array> */
    public function execute(array $tasks): array;
}
