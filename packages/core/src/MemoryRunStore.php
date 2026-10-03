<?php

declare(strict_types=1);

namespace Corevia\Core;

final class MemoryRunStore implements RunStore
{
    private array $runs = [];
    private array $keys = [];
    private array $locks = [];

    public function begin(array $run, ?string $key): array
    {
        $scope = $run['flow_name'] . ':' . $key;
        if ($key !== null && isset($this->keys[$scope])) {
            return $this->runs[$this->keys[$scope]];
        }
        if ($key !== null) {
            $this->keys[$scope] = $run['run_id'];
        }
        $this->save($run);
        return $run;
    }

    public function save(array $run): void
    {
        $this->runs[$run['run_id']] = $run;
    }

    public function get(string $runId): ?array
    {
        return $this->runs[$runId] ?? null;
    }

    public function lock(string $runId): bool
    {
        if (isset($this->locks[$runId])) {
            return false;
        }
        return $this->locks[$runId] = true;
    }

    public function unlock(string $runId): void
    {
        unset($this->locks[$runId]);
    }
}
