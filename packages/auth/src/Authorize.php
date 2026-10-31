<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Middleware,Request,Response};
final readonly class Authorize implements Middleware
{
    private ?\Closure $resource;
    public function __construct(private Gate $gate,private string $ability,?callable $resource=null,private bool $owned=false){$this->resource=$resource===null?null:\Closure::fromCallable($resource);}
    public function process(Request $request,callable $next):Response
    {
        $context=$request->attributes['auth']??null;$resource=$this->resource===null?null:($this->resource)($request);
        if(!$context instanceof AuthContext || ($this->resource!==null && !$resource instanceof ResourceIdentity) || !$this->gate->allows($context,$this->ability,$resource,$this->owned)){throw new AuthFailure('FORBIDDEN','You do not have permission for this action.',403);}return $next($request);
    }
}
