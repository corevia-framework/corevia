<?php

declare(strict_types=1);

namespace Learning\Tasks;

/** Optional database adapter for later. The default learning app never constructs it. */
final class PdoTaskRepository implements TaskRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    private function task(array $row): array
    {
        $row['completed'] = (bool) $row['completed'];
        return $row;
    }

    public function all(): array
    {
        $query = $this->pdo->query('SELECT id, title, completed, created_at FROM learning_tasks ORDER BY created_at, id');
        return array_map($this->task(...), $query->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(string $title): array
    {
        $task = ['id' => 'task_' . bin2hex(random_bytes(8)), 'title' => $title, 'completed' => false, 'created_at' => gmdate('c')];
        $query = $this->pdo->prepare('INSERT INTO learning_tasks (id, title, completed, created_at) VALUES (?, ?, 0, ?)');
        $query->execute([$task['id'], $title, $task['created_at']]);
        return $task;
    }

    public function complete(string $id): ?array
    {
        $query = $this->pdo->prepare('UPDATE learning_tasks SET completed = 1 WHERE id = ?');
        $query->execute([$id]);
        $query = $this->pdo->prepare('SELECT id, title, completed, created_at FROM learning_tasks WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->task($row);
    }
}
