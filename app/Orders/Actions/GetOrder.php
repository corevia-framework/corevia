<?php

declare(strict_types=1);

namespace App\Orders\Actions;

use Corevia\Core\{Action, ActionResult, ExecutionContext, RunStore, State};

final class GetOrder implements Action
{
    public function __construct(private readonly RunStore $store)
    {
    }

    public function handle(State $state, ExecutionContext $context): ActionResult
    {
        $run = $this->store->get((string) ($state->data['run_id'] ?? ''));
        return $run === null ? ActionResult::failure('NOT_FOUND', 'Run not found.') : ActionResult::success($run);
    }
}
