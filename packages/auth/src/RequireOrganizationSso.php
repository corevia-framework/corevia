<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{ApiResponse,Middleware,Request,Response};
final readonly class RequireOrganizationSso implements Middleware
{
    public function __construct(private OrganizationSso $policy,private string $tenant){}
    public function process(Request $request,callable $next):Response
    {
        try{$context=$request->attributes['auth']??null;if(!$context instanceof AuthContext){throw new AuthFailure('UNAUTHENTICATED','Sign in first.',401);}$this->policy->requireSession($context,$this->tenant);return $next($request);}
        catch(AuthFailure $error){return ApiResponse::error($error->errorCode,$error->getMessage(),$error->status);}
    }
}
