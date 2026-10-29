<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Request,Response,Router,View};

final class AuthRoutes
{
    public static function mount(Router $router,View $view,AuthService $auth,string $stack):void
    {
        if(!in_array($stack,['bootstrap','tailwind','react'],true)){throw new \InvalidArgumentException('Unknown auth presentation stack.');}
        $middleware=new SessionMiddleware($auth);
        $page=static function(Request $request,string $page,?string $error=null,int $status=200)use($view,$auth,$stack):Response{
            $context=$request->attributes['auth'];
            if($page==='register' && !$auth->registration){return new Response(['error'=>['code'=>'NOT_FOUND']],404);}
            if($page==='dashboard' && $context->user===null){return Response::redirect('/login');}
            if($page!=='dashboard' && $context->user!==null){return Response::redirect('/dashboard');}
            return Response::html($view->render($stack==='react'?'auth.shell':'auth.page',['page'=>$page,'csrf'=>$context->csrf,'user'=>$context->user,'error'=>$error,'stack'=>$stack,'registration'=>$auth->registration]),$status);
        };
        foreach(['login','register','dashboard'] as $name){$router->get('/'.$name,static fn(Request $request):Response=>$page($request,$name))->name('auth.'.$name)->middleware($middleware);}
        $router->get('/auth/session',static fn(Request $request):array=>['user'=>$request->attributes['auth']->user,'csrf'=>$request->attributes['auth']->csrf,'registration'=>$auth->registration])->middleware($middleware);
        $router->get('/auth/me',static fn(Request $request):Response=>$request->attributes['auth']->user===null?new Response(['error'=>['code'=>'UNAUTHENTICATED','message'=>'Sign in first.']],401):new Response(['user'=>$request->attributes['auth']->user]))->middleware($middleware);
        foreach(['login','register'] as $operation){
            foreach([false,true] as $json){
                $router->post(($json?'/auth/':'/').$operation,static function(Request $request)use($auth,$operation,$json,$page):Response{
                    try{$context=$auth->$operation($request,$request->attributes['auth']);}
                    catch(AuthFailure $error){if($json){throw $error;}return $page($request,$operation,$error->getMessage(),$error->status);}
                    $response=$json?new Response(['user'=>$context->user,'csrf'=>$context->csrf]):Response::redirect('/dashboard',303);
                    return $response->withCookie($context->cookie());
                })->middleware($middleware);
            }
        }
        foreach([false,true] as $json){$router->post($json?'/auth/logout':'/logout',static function(Request $request)use($auth,$json):Response{return ($json?new Response(['user'=>null]):Response::redirect('/login',303))->withCookie($auth->logout($request->attributes['auth']));})->middleware($middleware);}
    }
}
