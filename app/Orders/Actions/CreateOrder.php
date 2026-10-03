<?php

declare(strict_types=1);

namespace App\Orders\Actions;

use App\Shared\DemoLedger;
use Corevia\Core\{Action, ExecutionContext, State};

final class CreateOrder implements Action
{
    public function __construct(private readonly DemoLedger $ledger)
    {
    }

    public function handle(State $state, ExecutionContext $context): State
    {
        $key = $context->operationKey('orders.create');
        $order = $this->ledger->commit($key, 'order', ['order_id' => 'ord_' . substr($key, 0, 16), 'total' => $state->data['total']]);
        $state->data['order_id'] = $order['order_id'];
        return $state;
    }
}
