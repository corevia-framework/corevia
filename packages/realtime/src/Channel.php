<?php

declare(strict_types=1);

namespace Corevia\Realtime;

final readonly class Channel
{
    public function __construct(private string $name, private Broadcaster $broadcaster)
    {
    }

    public function broadcast(string $event, array $payload, ?string $traceId = null): void
    {
        $this->broadcaster->broadcast($this->name, $event, $payload, $traceId);
    }
}
