<?php

declare(strict_types=1);

namespace App\Shared;

/** Demo side effects with unique operation keys; this does not process real payments. */
final class DemoLedger
{
    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS demo_effects (operation_key TEXT PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL)');
    }

    public function commit(string $key, string $kind, array $payload): array
    {
        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO demo_effects VALUES (?, ?, ?)');
        $insert->execute([$key, $kind, json_encode($payload, JSON_THROW_ON_ERROR)]);
        $select = $this->pdo->prepare('SELECT payload FROM demo_effects WHERE operation_key = ?');
        $select->execute([$key]);
        return json_decode($select->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function count(string $kind): int
    {
        $select = $this->pdo->prepare('SELECT COUNT(*) FROM demo_effects WHERE kind = ?');
        $select->execute([$kind]);
        return (int) $select->fetchColumn();
    }
}
