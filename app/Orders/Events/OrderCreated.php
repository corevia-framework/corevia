<?php

declare(strict_types=1);

namespace App\Orders\Events;

final readonly class OrderCreated
{
    public function __construct(public string $orderId, public float $total, public string $traceId)
    {
    }
}
