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
    public int $timeoutMs = 0;
    public array $retryableExceptions = [];
    public bool $jitter = false;
    public ?string $compensation = null;
    public array $outputKeys = [];
    public ?string $checkpoint = null;
    public ?string $waitEvent = null;
    public array $parallelActions = [];

    public function __construct(public readonly string $action)
    {
    }
}
