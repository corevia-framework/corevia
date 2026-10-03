<?php

declare(strict_types=1);

namespace Learning\Tasks\Flows;

use Corevia\Core\Flow;

final class CreateTaskFlow
{
    public static function define(): Flow
    {
        // Critical failures stop here. Non-critical failures keep the saved task intact.
        return Flow::define('tasks.create')
            ->step('tasks.validateTitle')->critical()
            ->step('tasks.save')->critical()
            ->step('tasks.publishCreated')->nonCritical()
            ->step('tasks.createReminder')->nonCritical();
    }
}
