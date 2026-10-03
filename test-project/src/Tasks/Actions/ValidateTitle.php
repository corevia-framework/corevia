<?php

declare(strict_types=1);

namespace Learning\Tasks\Actions;

use Corevia\Core\Action;
use Corevia\Core\ActionResult;
use Corevia\Core\ExecutionContext;
use Corevia\Core\State;
use Corevia\Core\Validator;

final class ValidateTitle implements Action
{
    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        // State is the data passed from one action to the next.
        if (is_string($state->data['title'] ?? null)) {
            $state->data['title'] = trim($state->data['title']);
        }
        $errors = (new Validator())->validate($state->data, [
            'title' => ['required', 'string', 'min' => 1, 'max' => 120],
            'simulate_reminder_failure' => ['boolean'],
        ]);
        if ($errors !== []) {
            return ActionResult::failure('INVALID_TITLE', 'Enter a task title of 1–120 bytes; the simulation flag must be boolean.');
        }
        return $state;
    }
}
