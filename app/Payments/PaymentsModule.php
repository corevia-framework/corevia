<?php

declare(strict_types=1);

namespace App\Payments;

use App\Payments\Actions\ChargePayment;
use Corevia\Core\{Module, ModuleContext};

final class PaymentsModule implements Module
{
    public function name(): string
    {
        return 'payments';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function register(ModuleContext $context): void
    {
        $context->actions(['payments.charge' => ChargePayment::class]);
    }
}
