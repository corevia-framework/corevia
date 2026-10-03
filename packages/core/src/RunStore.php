<?php

declare(strict_types=1);

namespace Corevia\Core;

interface RunStore
{
    /** Atomically reserve an idempotency key and save initial run; returns existing run on conflict. */
    public function begin(array $run, ?string $key): array;

    public function save(array $run): void;

    public function get(string $runId): ?array;

    /** Exclusive lease for a manual retry. */
    public function lock(string $runId): bool;

    public function unlock(string $runId): void;
}
