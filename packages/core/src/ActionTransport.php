<?php
declare(strict_types=1);
namespace Corevia\Core;
interface ActionTransport
{
    public function call(string $target,array $payload):ActionResult;
}
