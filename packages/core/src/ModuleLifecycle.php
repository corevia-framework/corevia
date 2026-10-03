<?php

declare(strict_types=1);

namespace Corevia\Core;

/** Optional extension: register using Module; acquire/release resources through these hooks. */
interface ModuleLifecycle
{
    public function boot(ModuleContext $context): void;

    public function ready(ModuleContext $context): void;

    public function shutdown(ModuleContext $context): void;
}
