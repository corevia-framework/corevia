<?php

declare(strict_types=1);

namespace App\Orders\Actions;

use App\Orders\Events\OrderCreated;
use Corevia\Core\{Action, ActionResult, ExecutionContext, State};

final class PublishOrder implements Action
{
    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        $errors = $context->events->emit(new OrderCreated($state->data['order_id'], (float) $state->data['total'], $context->traceId));
        return $errors ? ActionResult::failure('EVENT_DELIVERY_FAILED', 'Order event listener failed.', true) : $state;
    }
}
