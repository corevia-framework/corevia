<?php

declare(strict_types=1);

namespace Learning\Tasks;

/** Database-free storage: one local JSON file, protected by a file lock. */
final class JsonTaskRepository implements TaskRepository
{
    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create task storage directory.');
        }
    }

    private function access(callable $operation, bool $write): mixed
    {
        $file = fopen($this->path, 'c+b');
        if ($file === false) {
            throw new \RuntimeException('Cannot open task storage.');
        }
        try {
            if (!flock($file, $write ? LOCK_EX : LOCK_SH)) {
                throw new \RuntimeException('Cannot lock task storage.');
            }
            $raw = stream_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException('Cannot read task storage.');
            }
            $tasks = trim($raw) === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($tasks) || !array_is_list($tasks)) {
                throw new \RuntimeException('Task storage must contain a JSON list.');
            }
            $result = $operation($tasks);
            if ($write) {
                $json = json_encode($tasks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                rewind($file);
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($file, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Cannot save task storage.');
                    }
                    $offset += $written;
                }
                if (!ftruncate($file, strlen($json)) || !fflush($file)) {
                    throw new \RuntimeException('Cannot finish saving task storage.');
                }
            }
            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public function all(): array
    {
        return $this->access(static fn (array $tasks): array => $tasks, false);
    }

    public function create(string $title): array
    {
        return $this->access(static function (array &$tasks) use ($title): array {
            $task = ['id' => 'task_' . bin2hex(random_bytes(8)), 'title' => $title, 'completed' => false, 'created_at' => gmdate('c')];
            $tasks[] = $task;
            return $task;
        }, true);
    }

    public function complete(string $id): ?array
    {
        return $this->access(static function (array &$tasks) use ($id): ?array {
            foreach ($tasks as &$task) {
                if ($task['id'] === $id) {
                    $task['completed'] = true;
                    return $task;
                }
            }
            return null;
        }, true);
    }
}
