<?php

declare(strict_types=1);

namespace App\Notifications\Actions;

use App\Shared\DemoLedger;
use Corevia\Core\{Action, ActionResult, ExecutionContext, State};

final class SendConfirmation implements Action
{
    public function __construct(private readonly DemoLedger $ledger, private readonly string $recoveryFile = '')
    {
    }

    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        if ($this->recoveryFile === '' || !is_file($this->recoveryFile)) {
            return ActionResult::failure('NOTIFICATION_UNAVAILABLE', 'Demo notification service is offline.', true);
        }
        $this->ledger->commit($context->operationKey('notifications.confirm'), 'confirmation', ['order_id' => $state->data['order_id']]);
        $state->data['confirmation_sent'] = true;
        return $state;
    }
}
