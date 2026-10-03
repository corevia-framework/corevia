<?php

declare(strict_types=1);

namespace Corevia\Core;

final readonly class ExecutionContext
{
    public function __construct(
        public string $runId,
        public string $traceId,
        public string $flow,
        public string $module,
        public EventBus $events,
        public Logger $logger,
        public string $requestId = '',
    ) {
    }

    /** Stable across retries; external side effects must use this key. */
    public function operationKey(string $action): string
    {
        return hash('sha256', $this->runId . ':' . $action);
    }
}
