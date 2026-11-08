<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Runtime-neutral schema port. Validation owns its documented schema subset. */
interface ActionContract
{
    public function assertInput(string $action,mixed $value):void;
    public function assertOutput(string $action,mixed $value):void;
    public function inputSchema(string $action):array;
    public function outputSchema(string $action):array;
}
