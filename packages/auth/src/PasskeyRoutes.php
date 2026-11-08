<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Request,Response,Router};
final class PasskeyRoutes
{
    public static function mount(Router $router,AuthService $auth,Passkeys $passkeys):void
    {
        $session=new SessionMiddleware($auth);
        $router->post('/auth/passkeys/register/options',fn(Request $request):array=>['publicKey'=>$passkeys->beginRegistration($request,$request->attributes['auth'])])->middleware($session);
        $router->post('/auth/passkeys/login/options',fn(Request $request):array=>['publicKey'=>$passkeys->beginLogin($request->attributes['auth'])])->middleware($session);
        foreach(['register','login'] as $kind){$router->post('/auth/passkeys/'.$kind.'/finish',static function(Request $request)use($passkeys,$kind):Response{
            $context=$kind==='register'?$passkeys->finishRegistration($request,$request->attributes['auth']):$passkeys->finishLogin($request,$request->attributes['auth']);return (new Response(['user'=>$context->user,'csrf'=>$context->csrf,'redirect'=>$kind==='register'?'/account':'/dashboard']))->withCookie($context->cookie());
        })->middleware($session);}
        $router->get('/auth/passkeys',fn(Request $request):array=>['passkeys'=>$passkeys->listing($request->attributes['auth'])])->middleware($session);
        $router->post('/auth/passkeys/remove',static function(Request $request)use($passkeys):Response{$context=$passkeys->remove($request,$request->attributes['auth']);return (new Response(['message'=>'Passkey removed.','csrf'=>$context->csrf,'redirect'=>'/account']))->withCookie($context->cookie());})->middleware($session);
    }
}
