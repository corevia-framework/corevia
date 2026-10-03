<?php

declare(strict_types=1);

namespace Corevia\Http;

final class Route
{
    public array $middlewares = [];

    public function __construct(public readonly string $method, public readonly string $path, public readonly \Closure $handler)
    {
    }

    public function middleware(Middleware $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }
}
