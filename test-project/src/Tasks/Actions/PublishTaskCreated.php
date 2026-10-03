<?php

declare(strict_types=1);

namespace Learning\Tasks\Actions;

use Corevia\Core\Action;
use Corevia\Core\ActionResult;
use Corevia\Core\ExecutionContext;
use Corevia\Core\State;
use Learning\Tasks\Events\TaskCreated;

final class PublishTaskCreated implements Action
{
    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        $task = $state->data['task'];
        $errors = $context->events->emit(new TaskCreated($task['id'], $task['title'], $context->traceId));
        if ($errors !== []) {
            return ActionResult::failure('EVENT_LISTENER_FAILED', 'A task event listener failed.');
        }
        $state->data['event_emitted'] = 'task.created';
        return $state;
    }
}
