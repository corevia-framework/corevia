<?php

declare(strict_types=1);

namespace Learning\Tasks\Actions;

use Corevia\Core\Action;
use Corevia\Core\ActionResult;
use Corevia\Core\ExecutionContext;
use Corevia\Core\State;
use Learning\Tasks\TaskRepository;

final class CompleteTask implements Action
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        $id = $state->data['id'] ?? null;
        if (!is_string($id) || !preg_match('/^task_[a-f0-9]{16}$/D', $id)) {
            return ActionResult::failure('INVALID_TASK_ID', 'Use the ID of an existing task.');
        }
        $task = $this->tasks->complete($id);
        if ($task === null) {
            return ActionResult::failure('TASK_NOT_FOUND', 'Task not found.');
        }
        $state->data['task'] = $task;
        return $state;
    }
}
