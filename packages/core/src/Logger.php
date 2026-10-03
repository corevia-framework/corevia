<?php

declare(strict_types=1);

namespace Corevia\Core;

/** Compatible with the PSR-3 log method; accepts a PSR-3 logger through LoggerAdapter. */
interface Logger
{
    public function log(string $level, string $message, array $context = []): void;
}
