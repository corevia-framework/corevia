<?php

declare(strict_types=1);

namespace App\Orders\Actions;

use Corevia\Core\{Action, ActionResult, ExecutionContext, State};
use Corevia\Protocol\RemoteAction;

final class FraudCheck implements Action
{
    public function __construct(private readonly ?RemoteAction $remote = null)
    {
    }

    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        if ($this->remote !== null) {
            $result = $this->remote->call('fraud.check', ['total' => $state->data['total']], $context->traceId, $context->operationKey('fraud.check'));
            if (!$result->ok) {
                return $result;
            }
            if (!is_array($result->data) || !is_bool($result->data['approved'] ?? null)) {
                return ActionResult::failure('REMOTE_PROTOCOL_ERROR', 'Fraud worker returned an invalid decision.');
            }
            $approved = $result->data['approved'];
        } else {
            $approved = $state->data['total'] <= 10000;
        }
        if (!$approved) {
            return ActionResult::failure('FRAUD_REJECTED', 'Demo risk threshold exceeded.');
        }
        $state->data['fraud_checked'] = true;
        $state->data['fraud_runtime'] = $this->remote === null ? 'local' : 'python';
        return $state;
    }
}
