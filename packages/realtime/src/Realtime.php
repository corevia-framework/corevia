<?php

declare(strict_types=1);

namespace Corevia\Realtime;

final readonly class Realtime
{
    public function __construct(private Broadcaster $broadcaster)
    {
    }

    public function channel(string $name): Channel
    {
        return new Channel($name, $this->broadcaster);
    }
}
