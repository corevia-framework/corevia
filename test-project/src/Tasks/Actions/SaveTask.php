<?php

declare(strict_types=1);

namespace Learning\Tasks\Actions;

use Corevia\Core\Action;
use Corevia\Core\ExecutionContext;
use Corevia\Core\State;
use Learning\Tasks\TaskRepository;

final class SaveTask implements Action
{
    // Corevia's container injects whichever storage implementation bootstrap binds.
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function handle(State $state, ExecutionContext $context): State
    {
        $state->data['task'] = $this->tasks->create($state->data['title']);
        return $state;
    }
}
