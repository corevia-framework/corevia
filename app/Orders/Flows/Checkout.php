<?php

declare(strict_types=1);

namespace App\Orders\Flows;

use App\Orders\State\CheckoutState;
use Corevia\Core\Flow;

final class Checkout
{
    public static function define(): Flow
    {
        return Flow::define('checkout', CheckoutState::class)
            ->step('orders.validate')->critical()
            ->step('orders.total')->critical()
            ->step('orders.fraud')->critical()->retry(2, 20, true)
            ->step('payments.charge')->critical()->retry(2)
            ->step('orders.create')->critical()
            ->step('notifications.confirm')->nonCritical()->retry(5, 5)
            ->step('orders.publish')->nonCritical()->retry(1, 10);
    }
}
