<?php

declare(strict_types=1);

namespace Learning\Tasks;

use Corevia\Core\Flow;
use Corevia\Core\Logger;
use Corevia\Core\Module;
use Corevia\Core\ModuleContext;
use Learning\Tasks\Actions\CompleteTask;
use Learning\Tasks\Actions\CreateReminder;
use Learning\Tasks\Actions\PublishTaskCreated;
use Learning\Tasks\Actions\SaveTask;
use Learning\Tasks\Actions\ValidateTitle;
use Learning\Tasks\Events\TaskCreated;
use Learning\Tasks\Flows\CreateTaskFlow;

final class TasksModule implements Module
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function name(): string
    {
        return 'tasks';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function register(ModuleContext $context): void
    {
        $context->actions([
            'tasks.validateTitle' => ValidateTitle::class,
            'tasks.save' => SaveTask::class,
            'tasks.publishCreated' => PublishTaskCreated::class,
            'tasks.createReminder' => CreateReminder::class,
            'tasks.complete' => CompleteTask::class,
        ]);
        $context->flows([
            CreateTaskFlow::define(),
            Flow::define('tasks.complete')->step('tasks.complete')->critical(),
        ]);
        $context->on(TaskCreated::class, function (TaskCreated $event): void {
            $this->logger->log('info', 'task.created event received', [
                'module' => 'tasks', 'task_id' => $event->id, 'trace_id' => $event->traceId,
            ]);
        });
    }
}
