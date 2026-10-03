<?php

declare(strict_types=1);

namespace App\Orders;

use App\Orders\Actions\{CalculateTotal, CreateOrder, FraudCheck, GetOrder, PublishOrder, ValidateCart};
use App\Orders\Events\OrderCreated;
use App\Orders\Flows\Checkout;
use Corevia\Core\{Module, ModuleContext};
use Corevia\Realtime\Broadcaster;

final class OrdersModule implements Module
{
    public function __construct(private readonly Broadcaster $broadcaster)
    {
    }

    public function name(): string
    {
        return 'orders';
    }

    public function dependencies(): array
    {
        return ['payments', 'notifications'];
    }

    public function register(ModuleContext $context): void
    {
        $context->actions(['orders.validate' => ValidateCart::class, 'orders.total' => CalculateTotal::class, 'orders.fraud' => FraudCheck::class, 'orders.create' => CreateOrder::class, 'orders.publish' => PublishOrder::class, 'orders.get' => GetOrder::class]);
        $context->flows([Checkout::define()]);
        $context->on(OrderCreated::class, function (OrderCreated $event): void {
            $this->broadcaster->broadcast('orders', 'order.updated', ['order_id' => $event->orderId, 'total' => $event->total, 'status' => 'created'], $event->traceId);
        });
    }
}
