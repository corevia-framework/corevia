<?php

declare(strict_types=1);

namespace Learning\Tasks;

/** Business code uses this contract, so storage can change without rewriting actions. */
interface TaskRepository
{
    /** @return list<array{id: string, title: string, completed: bool, created_at: string}> */
    public function all(): array;

    public function create(string $title): array;

    public function complete(string $id): ?array;
}
