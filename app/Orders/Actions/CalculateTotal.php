<?php

declare(strict_types=1);

namespace App\Orders\Actions;

use Corevia\Core\{Action, ExecutionContext, State};

final class CalculateTotal implements Action
{
    public function handle(State $state, ExecutionContext $context): State
    {
        $state->data['total'] = round(array_sum(array_column($state->data['items'], 'price')), 2);
        return $state;
    }
}
