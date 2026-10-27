<?php
declare(strict_types=1);
namespace Corevia\Realtime;

/** Optional external fan-out. Implement bounded nonblocking poll and deduplicate transport delivery. */
interface PubSub
{
    public function publish(array $event): void;
    /** @return list<array> */
    public function poll(): array;
}
