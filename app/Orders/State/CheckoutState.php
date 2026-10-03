<?php

declare(strict_types=1);

namespace App\Orders\State;

use Corevia\Core\State;

final class CheckoutState extends State
{
    public function items(): array
    {
        return $this->data['items'] ?? [];
    }

    public function total(): float
    {
        return (float) ($this->data['total'] ?? 0);
    }
}
