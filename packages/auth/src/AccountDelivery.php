<?php
declare(strict_types=1);
namespace Corevia\Auth;
interface AccountDelivery
{
    /** Implement with a private mail transport/queue. Never log the bearer link. */
    public function deliver(AccountMessage $message):void;
}
