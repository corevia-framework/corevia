<?php

declare(strict_types=1);

namespace Corevia\Http;

interface Middleware
{
    public function process(Request $request, callable $next): Response;
}
