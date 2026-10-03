<?php

declare(strict_types=1);

namespace Corevia\Core;

final class LoggerAdapter implements Logger
{
    public function __construct(private readonly object $logger)
    {
        if (!is_callable([$logger, 'log'])) {
            throw new \InvalidArgumentException('Logger must expose log().');
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
