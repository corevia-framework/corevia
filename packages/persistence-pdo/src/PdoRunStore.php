<?php

declare(strict_types=1);

namespace Corevia\Persistence;

use Corevia\Core\RunStore;

/** SQLite reference adapter. SQL is intentionally isolated from the core package. */
final class PdoRunStore implements RunStore
{
    public function __construct(private readonly \PDO $pdo)
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('The reference run store requires PDO SQLite.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS corevia_flow_runs (run_id TEXT PRIMARY KEY, flow_name TEXT NOT NULL, status TEXT NOT NULL, trace_id TEXT NOT NULL, started_at TEXT NOT NULL, finished_at TEXT, input_hash TEXT NOT NULL, snapshot TEXT NOT NULL, locked INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS corevia_flow_steps (run_id TEXT NOT NULL, step_index INTEGER NOT NULL, snapshot TEXT NOT NULL, PRIMARY KEY(run_id, step_index))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS corevia_idempotency (flow_name TEXT NOT NULL, idem_key TEXT NOT NULL, run_id TEXT NOT NULL, PRIMARY KEY(flow_name, idem_key))');
    }

    public function begin(array $run, ?string $key): array
    {
        $this->pdo->beginTransaction();
        try {
            if ($key !== null) {
                $statement = $this->pdo->prepare('INSERT OR IGNORE INTO corevia_idempotency (flow_name, idem_key, run_id) VALUES (?, ?, ?)');
                $statement->execute([$run['flow_name'], $key, $run['run_id']]);
                if ($statement->rowCount() === 0) {
                    $query = $this->pdo->prepare('SELECT run_id FROM corevia_idempotency WHERE flow_name = ? AND idem_key = ?');
                    $query->execute([$run['flow_name'], $key]);
                    $existing = $this->get($query->fetchColumn()) ?? throw new \RuntimeException('Missing idempotent run.');
                    $this->pdo->commit();
                    return $existing;
                }
            }
            $this->save($run);
            $this->pdo->commit();
            return $run;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function save(array $run): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $query = $this->pdo->prepare('INSERT INTO corevia_flow_runs (run_id, flow_name, status, trace_id, started_at, finished_at, input_hash, snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(run_id) DO UPDATE SET status=excluded.status, finished_at=excluded.finished_at, snapshot=excluded.snapshot');
            $query->execute([$run['run_id'], $run['flow_name'], $run['status'], $run['trace_id'], $run['started_at'], $run['finished_at'], $run['input_hash'], json_encode($run, JSON_THROW_ON_ERROR)]);
            foreach ($run['steps'] as $index => $step) {
                $query = $this->pdo->prepare('INSERT INTO corevia_flow_steps VALUES (?, ?, ?) ON CONFLICT(run_id, step_index) DO UPDATE SET snapshot=excluded.snapshot');
                $query->execute([$run['run_id'], $index, json_encode($step, JSON_THROW_ON_ERROR)]);
            }
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function get(string $runId): ?array
    {
        $query = $this->pdo->prepare('SELECT snapshot FROM corevia_flow_runs WHERE run_id = ?');
        $query->execute([$runId]);
        $json = $query->fetchColumn();
        return $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function lock(string $runId): bool
    {
        $query = $this->pdo->prepare('UPDATE corevia_flow_runs SET locked=1 WHERE run_id=? AND locked=0');
        $query->execute([$runId]);
        return $query->rowCount() === 1;
    }

    public function unlock(string $runId): void
    {
        $query = $this->pdo->prepare('UPDATE corevia_flow_runs SET locked=0 WHERE run_id=?');
        $query->execute([$runId]);
    }
}
