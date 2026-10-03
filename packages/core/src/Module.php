<?php

declare(strict_types=1);

namespace Corevia\Core;

interface Module
{
    public function name(): string;

    /** @return list<string> Module IDs. */
    public function dependencies(): array;

    public function register(ModuleContext $context): void;
}
