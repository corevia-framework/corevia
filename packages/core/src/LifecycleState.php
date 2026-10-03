<?php

declare(strict_types=1);

namespace Corevia\Core;

enum LifecycleState: string
{
    case Created = 'created';
    case Registering = 'registering';
    case Booting = 'booting';
    case Ready = 'ready';
    case Failed = 'failed';
    case ShuttingDown = 'shutting_down';
    case Shutdown = 'shutdown';
}
