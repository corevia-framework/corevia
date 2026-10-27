<?php

declare(strict_types=1);

namespace Corevia\Core;

/** Optional extension: V1 modules do not need to implement it. */
interface ManifestProvider
{
    public function manifest(): ModuleManifest;
}
