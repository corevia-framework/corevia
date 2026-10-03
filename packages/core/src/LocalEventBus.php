<?php

declare(strict_types=1);

namespace Corevia\Core;

final class LocalEventBus implements EventBus
{
    private array $handlers = [];

    public function __construct(private readonly Logger $logger)
    {
    }

    public function on(string $event, callable $handler): void
    {
        $this->handlers[$event][] = $handler;
    }

    public function emit(object $event): array
    {
        $errors = [];
        foreach ($this->handlers[$event::class] ?? [] as $handler) {
            try {
                $handler($event);
            } catch (\Throwable $exception) {
                $errors[] = new Error('EVENT_HANDLER_FAILED', 'Event listener failed.');
                $this->logger->log('error', 'Event handler failed', ['event' => $event::class, 'exception' => $exception::class]);
            }
        }
        return $errors;
    }
}
