<?php

declare(strict_types=1);

namespace App\Payments\Actions;

use App\Shared\DemoLedger;
use Corevia\Core\{Action, ActionResult, ExecutionContext, State};

final class ChargePayment implements Action
{
    public function __construct(private readonly DemoLedger $ledger)
    {
    }

    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        if (($state->data['decline_payment'] ?? false) === true) {
            return ActionResult::failure('PAYMENT_DECLINED', 'Demo payment declined.');
        }
        $key = $context->operationKey('payments.charge');
        $charge = $this->ledger->commit($key, 'charge', ['payment_id' => 'pay_' . substr($key, 0, 16), 'amount' => $state->data['total']]);
        $state->data['payment_id'] = $charge['payment_id'];
        return $state;
    }
}
