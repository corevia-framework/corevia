<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Step
{
    public bool $isCritical = true;
    public int $retries = 0;
    public int $delayMs = 0;
    public bool $exponential = false;
    public ?\Closure $condition = null;

    public function __construct(public readonly string $action)
    {
    }
}
