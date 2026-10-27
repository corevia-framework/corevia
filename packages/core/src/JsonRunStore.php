<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Small single-node durable store. File locks coordinate PHP processes without database extensions. */
final class JsonRunStore implements RunStore
{
    private array $leases = [];
    public function __construct(private readonly string $path)
    {
        if (!is_dir(dirname($path))) { throw new \InvalidArgumentException('Create a private run-store directory first.'); }
    }
    private function transaction(callable $operation, bool $write = true): mixed
    {
        $lock = fopen($this->path . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Cannot lock run store.'); }
        try {
            $data = ['runs' => [], 'keys' => []];
            if (is_file($this->path)) {
                if (filesize($this->path) > 16777216) { throw new \LengthException('Run store exceeds 16MiB; use a database adapter.'); }
                $data = json_decode(file_get_contents($this->path), true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($data['runs'] ?? null) || !is_array($data['keys'] ?? null)) { throw new \RuntimeException('Invalid run store.'); }
            }
            $result = $operation($data);
            if (!$write) { return $result; }
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (strlen($json) > 16777216) { throw new \LengthException('Run store exceeds 16MiB.'); }
            $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            try {
                if (file_put_contents($temporary, $json, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $this->path)) { throw new \RuntimeException('Cannot save run store.'); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public function begin(array $run, ?string $key): array
    {
        return $this->transaction(function (array &$data) use ($run, $key): array {
            $scope = hash('sha256', $run['flow_name'] . "\0" . $key);
            if ($key !== null && isset($data['keys'][$scope])) { return $data['runs'][$data['keys'][$scope]]; }
            $data['runs'][$run['run_id']] = $run;
            if ($key !== null) { $data['keys'][$scope] = $run['run_id']; }
            return $run;
        });
    }
    public function save(array $run): void { $this->transaction(function (array &$data) use ($run): void { $data['runs'][$run['run_id']] = $run; }); }
    public function get(string $runId): ?array { return $this->transaction(fn (array $data): ?array => $data['runs'][$runId] ?? null, false); }
    public function lock(string $runId): bool
    {
        if (isset($this->leases[$runId])) { return false; }
        $lease = fopen($this->path . '.' . hash('sha256', $runId) . '.lease', 'c+b');
        if ($lease === false) { throw new \RuntimeException('Cannot open lease.'); }
        if (!flock($lease, LOCK_EX | LOCK_NB)) { fclose($lease); return false; }
        $this->leases[$runId] = $lease; return true;
    }
    public function unlock(string $runId): void
    {
        if (isset($this->leases[$runId])) { flock($this->leases[$runId], LOCK_UN); fclose($this->leases[$runId]); unset($this->leases[$runId]); }
    }
    public function __destruct() { foreach (array_keys($this->leases) as $id) { $this->unlock($id); } }
}
