<?php

declare(strict_types=1);

namespace Corevia\Realtime;

interface Broadcaster
{
    public function broadcast(string $channel, string $event, array $payload, ?string $traceId = null): void;
}
