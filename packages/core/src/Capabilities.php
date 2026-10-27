<?php

declare(strict_types=1);

namespace Corevia\Core;

/** Adapters explicitly describe configured functionality; discovery never starts services. */
final class Capabilities
{
    private array $items = [];

    public function define(string $name, bool $available = true, array $metadata = []): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $name)) {
            throw new \InvalidArgumentException('Invalid capability name.');
        }
        $this->items[$name] = ['available' => $available, 'metadata' => $metadata];
    }

    public function has(string $name): bool
    {
        return ($this->items[$name]['available'] ?? false) === true;
    }

    public function all(): array
    {
        return $this->items;
    }
}
