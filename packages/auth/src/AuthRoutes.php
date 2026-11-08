<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Request,Response,Router,View};

final class AuthRoutes
{
    public static function mount(Router $router,View $view,AuthService $auth,string $stack,?AccountRecovery $accounts=null,bool $requireVerified=false,?AccountManager $manager=null,?MfaService $mfa=null,?Gate $gate=null,?OAuthService $oauth=null,?Passkeys $passkeys=null,?AccountLifecycle $lifecycle=null):void
    {
        if(!in_array($stack,['bootstrap','tailwind','react'],true)){throw new \InvalidArgumentException('Unknown auth presentation stack.');}
        $middleware=new SessionMiddleware($auth);
        if($requireVerified && $accounts===null){throw new \LogicException('A verification gate requires account delivery.');}
        $page=static function(Request $request,string $page,?string $error=null,int $status=200)use($view,$auth,$stack,$accounts,$requireVerified,$oauth,$passkeys):Response{
            $context=$request->attributes['auth'];
            if($page==='register' && !$auth->registration){return new Response(['error'=>['code'=>'NOT_FOUND']],404);}
            if($page==='dashboard' && $context->user===null){return Response::redirect('/login');}
            if($page==='dashboard' && $requireVerified && !$accounts->isVerified($context)){return Response::redirect('/verify-email');}
            if($page!=='dashboard' && $context->user!==null){return Response::redirect('/dashboard');}
            return Response::html($view->render($stack==='react'?'auth.shell':'auth.page',['ui'=>AuthPresentation::for($request),'page'=>$page,'csrf'=>$context->csrf,'user'=>$context->user,'error'=>$error,'stack'=>$stack,'registration'=>$auth->registration,'recoveryEnabled'=>$accounts!==null,'emailVerified'=>$accounts?->isVerified($context)??false,'providers'=>$oauth?->providers()??[],'passkeysAvailable'=>$passkeys!==null]),$status);
        };
        foreach(['login','register','dashboard'] as $name){$router->get('/'.$name,static fn(Request $request):Response=>$page($request,$name))->name('auth.'.$name)->middleware($middleware);}
        $router->get('/auth/session',static fn(Request $request):array=>['user'=>$request->attributes['auth']->user,'csrf'=>$request->attributes['auth']->csrf,'registration'=>$auth->registration,'recovery'=>$accounts!==null,'email_verified'=>$accounts?->isVerified($request->attributes['auth'])??false,'providers'=>$oauth?->providers()??[],'passkeys'=>$passkeys!==null,'data_lifecycle'=>$lifecycle!==null,'mfa_available'=>$mfa!==null,'mfa_enabled'=>$request->attributes['auth']->user!==null&&($mfa?->enabled($request->attributes['auth']->user['id'])??false)])->middleware($middleware);
        $router->get('/auth/me',static fn(Request $request):Response=>$request->attributes['auth']->user===null?new Response(['error'=>['code'=>'UNAUTHENTICATED','message'=>'Sign in first.']],401):new Response(['user'=>$request->attributes['auth']->user]))->middleware($middleware);
        foreach(['login','register'] as $operation){
            foreach([false,true] as $json){
                $router->post(($json?'/auth/':'/').$operation,static function(Request $request)use($auth,$operation,$json,$page,$accounts):Response{
                    try{$context=$auth->$operation($request,$request->attributes['auth']);}
                    catch(AuthFailure $error){if($json){throw $error;}return $page($request,$operation,$error->getMessage(),$error->status);}
                    if($operation==='register'){$accounts?->afterRegistration($context);}
                    $response=$json?new Response(['user'=>$context->user,'csrf'=>$context->csrf]):Response::redirect('/dashboard',303);
                    return $response->withCookie($context->cookie());
                })->middleware($middleware);
            }
        }
        foreach([false,true] as $json){$router->post($json?'/auth/logout':'/logout',static function(Request $request)use($auth,$json):Response{return ($json?new Response(['user'=>null]):Response::redirect('/login',303))->withCookie($auth->logout($request->attributes['auth']));})->middleware($middleware);}
        if($passkeys!==null){PasskeyRoutes::mount($router,$auth,$passkeys);}
        if($lifecycle!==null){
            if($manager===null){throw new \LogicException('Enable account management before mounting account lifecycle routes.');}
            $router->post('/auth/account/export',static function(Request $request)use($lifecycle):Response{return (new Response($lifecycle->export($request,$request->attributes['auth'])))->withHeaders(['Content-Disposition'=>'attachment; filename=corevia-account-export.json']);})->middleware($middleware);
        }
        if($accounts!==null){AccountRoutes::mount($router,$view,$auth,$accounts,$stack,$middleware);}
        if($manager!==null){ManagementRoutes::mount($router,$view,$auth,$manager,$stack,$middleware,$mfa,$gate,$oauth,$accounts,$passkeys,$lifecycle);}
    }
}
