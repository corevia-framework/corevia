<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Actions\SendConfirmation;
use Corevia\Core\{Module, ModuleContext};

final class NotificationsModule implements Module
{
    public function name(): string
    {
        return 'notifications';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function register(ModuleContext $context): void
    {
        $context->actions(['notifications.confirm' => SendConfirmation::class]);
    }
}
