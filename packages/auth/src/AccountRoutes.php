<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Request,Response,Router,View};

final class AccountRoutes
{
    public static function mount(Router $router,View $view,AuthService $auth,AccountRecovery $accounts,string $stack,SessionMiddleware $session):void
    {
        $page=static function(Request $request,string $page,?string $notice=null)use($view,$accounts,$stack):Response{
            $context=$request->attributes['auth'];
            return Response::html($view->render($stack==='react'?'auth.shell':'auth.account',['page'=>$page,'csrf'=>$context->csrf,'user'=>$context->user,'stack'=>$stack,'verified'=>$accounts->isVerified($context),'notice'=>$notice]));
        };
        foreach(['forgot-password','reset-password','verify-email'] as $name){$router->get('/'.$name,static fn(Request $request):Response=>$page($request,$name))->middleware($session);}
        $router->post('/forgot-password',static function(Request $request)use($accounts,$page):Response{$accounts->requestReset($request);return $page($request,'forgot-password','If an account exists, a recovery link has been requested.');})->middleware($session);
        $router->post('/auth/password/forgot',static function(Request $request)use($accounts):array{$accounts->requestReset($request);return ['message'=>'If an account exists, a recovery link has been requested.'];})->middleware($session);
        $router->post('/auth/password/reset',static function(Request $request)use($accounts,$auth):Response{$accounts->resetPassword($request);return (new Response(['message'=>'Password updated. Sign in again.','user'=>null]))->withCookie($auth->logout($request->attributes['auth']));})->middleware($session);
        $router->post('/auth/email/resend',static function(Request $request)use($accounts):array{$accounts->requestVerification($request,$request->attributes['auth']);return ['message'=>'A verification link has been requested.'];})->middleware($session);
        $router->post('/auth/email/verify',static function(Request $request)use($accounts):array{$accounts->verifyEmail($request,$request->attributes['auth']);return ['message'=>'Email verified.','email_verified'=>true];})->middleware($session);
        $router->get('/auth/verified',static fn():array=>['verified'=>true])->middleware($session)->middleware(new RequireVerifiedEmail($accounts));
    }
}
