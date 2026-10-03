<?php

declare(strict_types=1);

namespace Learning\Tasks\Actions;

use Corevia\Core\Action;
use Corevia\Core\ActionResult;
use Corevia\Core\ExecutionContext;
use Corevia\Core\State;

final class CreateReminder implements Action
{
    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        // An expected failure is a result, rather than an uncaught exception.
        if (($state->data['simulate_reminder_failure'] ?? false) === true) {
            return ActionResult::failure('DEMO_REMINDER_FAILED', 'Simulated reminder failure. Your task was still saved.');
        }
        // This learning app produces a suggestion; it does not send email or schedule jobs.
        $state->data['reminder'] = 'Remember to finish: ' . $state->data['task']['title'];
        return $state;
    }
}
