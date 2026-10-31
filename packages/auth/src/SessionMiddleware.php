<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Middleware,Request,Response,View};
final readonly class SessionMiddleware implements Middleware
{
    public function __construct(private AuthService $auth){}
    public function process(Request $request,callable $next):Response
    {
        try{
            $context=$this->auth->open($request);
            if($context===null){throw new AuthFailure('CSRF','Reload the page and try again.',419);}
            if(!in_array($request->method,['GET','HEAD'],true)){$this->auth->verify($request,$context);}
            $response=$next($request->withAttribute('auth',$context));
            if($context->cookie()!==null){$response=$response->withCookie($context->cookie());}
        }catch(AuthFailure $error){$response=str_starts_with($request->path,'/auth/')?new Response(['error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],$error->status):Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Authentication</title><h1>'.View::escape($error->getMessage()).'</h1><p><a href="/login">Back to sign in</a></p></html>',$error->status);}
        if($response->status===429){$response=$response->withHeaders(['Retry-After'=>'900']);}
        $referrer=str_starts_with($request->path,'/auth/oauth/')&&str_ends_with($request->path,'/callback')?'no-referrer':'same-origin';
        return $response->withHeaders(['Cache-Control'=>'no-store','Referrer-Policy'=>$referrer,'X-Frame-Options'=>'DENY','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"]);
    }
}
