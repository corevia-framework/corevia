<?php

declare(strict_types=1);

namespace Corevia\Core;

interface Action
{
    public function handle(State $state, ExecutionContext $context): State|ActionResult;
}
