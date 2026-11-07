<?php

declare(strict_types=1);

namespace Corevia\Http;

final class Route
{
    public array $middlewares = [];
    public array $constraints = [];
    public ?string $routeName = null;
    private array $documentation=[];

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
    public function describe(string $operation,array $input=[],array $output=[],array $scopes=[],int $successStatus=200):self
    {
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,100}$/D',$operation)){throw new \InvalidArgumentException('Invalid API operation ID.');}
        if(!array_is_list($scopes)||count($scopes)>32||$successStatus<200||$successStatus>299){throw new \InvalidArgumentException('Invalid API scopes or success status.');}
        foreach($scopes as $scope){if(!is_string($scope)||!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$scope)){throw new \InvalidArgumentException('Invalid documented API scope.');}}
        $this->documentation=compact('operation','input','output','scopes','successStatus');return $this;
    }
    public function documentation():array{return $this->documentation;}
}
