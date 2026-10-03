<?php

declare(strict_types=1);

namespace Corevia\Core;

interface EventBus
{
    public function on(string $event, callable $handler): void;

    /** @return list<Error> Listener failures are isolated and reported. */
    public function emit(object $event): array;
}
