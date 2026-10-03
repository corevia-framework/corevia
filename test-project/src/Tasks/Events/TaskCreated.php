<?php

declare(strict_types=1);

namespace Learning\Tasks\Events;

final readonly class TaskCreated
{
    public function __construct(public string $id, public string $title, public string $traceId)
    {
    }
}
