<?php

declare(strict_types=1);

namespace Corevia\Http;

final class Route
{
    public array $middlewares = [];
    public array $constraints = [];
    public ?string $routeName = null;

    public function __construct(public readonly string $method, public readonly string $path, public readonly \Closure $handler, private readonly ?\Closure $nameValidator = null, private readonly string $namePrefix = '')
    {
    }

    public function name(string $name): self
    {
        $name=$this->namePrefix.$name;
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D',$name)){throw new \InvalidArgumentException('Invalid route name.');}
        if($this->nameValidator!==null){($this->nameValidator)($name,$this);}$this->routeName=$name;return $this;
    }
    public function where(string $parameter,string $pattern): self
    {
        if(!str_contains($this->path,'{'.$parameter.'}') || $pattern==='' || str_contains($pattern,'#') || @preg_match('#^(?:'.$pattern.')$#D','')===false){throw new \InvalidArgumentException('Invalid route constraint.');}
        $this->constraints[$parameter]=$pattern;return $this;
    }

    public function middleware(Middleware $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }
}
