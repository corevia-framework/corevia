<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Middleware,Request,Response};
final readonly class RequireVerifiedEmail implements Middleware
{
    public function __construct(private AccountRecovery $accounts){}
    public function process(Request $request,callable $next):Response
    {
        $context=$request->attributes['auth']??null;$json=str_starts_with($request->path,'/auth/') || str_contains($request->header('accept'),'application/json');
        if(!$context instanceof AuthContext || $context->user===null){return $json?new Response(['error'=>['code'=>'UNAUTHENTICATED','message'=>'Sign in first.']],401):Response::redirect('/login');}
        if(!$this->accounts->isVerified($context)){return $json?new Response(['error'=>['code'=>'EMAIL_NOT_VERIFIED','message'=>'Verify your email first.']],403):Response::redirect('/verify-email');}
        return $next($request);
    }
}
