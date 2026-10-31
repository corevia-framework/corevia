<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{Request,Response,Router,View};
final class ManagementRoutes
{
    public static function mount(Router $router,View $view,AuthService $auth,AccountManager $manager,string $stack,SessionMiddleware $session,?MfaService $mfa=null,?Gate $gate=null,?OAuthService $oauth=null,?AccountRecovery $accounts=null):void
    {
        $router->get('/account',static function(Request $request)use($view,$stack,$mfa,$oauth,$manager):Response{$context=$request->attributes['auth'];if($context->user===null){return Response::redirect('/login');}return Response::html($view->render('auth.settings',['stack'=>$stack,'csrf'=>$context->csrf,'user'=>$context->user,'mfaAvailable'=>$mfa!==null,'mfaEnabled'=>$mfa?->enabled($context->user['id'])??false,'providers'=>$oauth?->providers()??[],'sessions'=>$manager->sessions($context)]));})->middleware($session);
        foreach(['profile','email','password','delete'] as $operation){$router->post('/auth/account/'.$operation,static function(Request $request)use($auth,$manager,$operation,$accounts):Response{
            $old=$request->attributes['auth'];$result=$manager->update($request,$old,$operation);if($result['user']===null){return (new Response(['message'=>'Account deleted.','redirect'=>'/login']))->withCookie($auth->logout($old));}
            $fresh=$auth->replaceSession($old,$result['user'],true);if($operation==='email'){$accounts?->afterRegistration($fresh);}return (new Response(['message'=>'Account updated.','csrf'=>$fresh->csrf,'redirect'=>'/account']))->withCookie($fresh->cookie());
        })->middleware($session);}
        $router->get('/auth/account/sessions',static fn(Request $request):array=>['sessions'=>$manager->sessions($request->attributes['auth'])])->middleware($session);
        $router->post('/auth/account/sessions/revoke',static function(Request $request)use($manager):array{$manager->revoke($request,$request->attributes['auth']);return ['message'=>'Selected sessions revoked.','redirect'=>'/account'];})->middleware($session);
        if($mfa!==null){foreach(['setup','enable','disable'] as $operation){$router->post('/auth/mfa/'.$operation,static function(Request $request)use($auth,$mfa,$operation):Response{
            $context=$request->attributes['auth'];$user=$auth->confirm($request,$context);
            if($operation==='setup'){return new Response(['message'=>'Add this secret to your authenticator, then confirm its current code.','setup'=>$mfa->begin($user,$context,parse_url($auth->origin,PHP_URL_HOST))]);}
            $outcome=$operation==='enable'?$mfa->enable($user,$context,$request->input()['code']??null):$mfa->disable($user);$fresh=$auth->replaceSession($context,$outcome['user'],true);
            return (new Response(['message'=>$operation==='enable'?'Authenticator enabled. Save these recovery codes privately; they are shown once.':'Authenticator disabled.','csrf'=>$fresh->csrf,'mfa_enabled'=>$operation==='enable','recovery_codes'=>$outcome['value']]))->withCookie($fresh->cookie());
        })->middleware($session);}}
        if($gate!==null){$router->get('/auth/admin',static fn():array=>['message'=>'Authorized admin workspace.'])->middleware($session)->middleware(new Authorize($gate,'admin.access'));}
        if($oauth!==null){
            foreach($oauth->providers() as $provider){
                $router->post('/auth/oauth/'.$provider.'/start',static function(Request $request)use($oauth,$provider):Response{$url=$oauth->start($request,$request->attributes['auth'],$provider);return str_contains($request->header('accept'),'application/json')?new Response(['authorization_url'=>$url]):Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Provider sign in</title><h1>Provider sign in</h1><p><a href="'.View::escape($url).'">Continue to '.View::escape($provider).'</a></p></html>');})->middleware($session);
                $router->get('/auth/oauth/'.$provider.'/callback',static function(Request $request)use($oauth,$provider):Response{$old=$request->attributes['auth'];$fresh=$oauth->callback($request,$old,$provider);$response=Response::redirect($old->user!==null?'/account':($fresh===null?'/mfa-challenge':'/dashboard'),303);return $fresh===null?$response:$response->withCookie($fresh->cookie());})->middleware($session);
                $router->post('/auth/oauth/'.$provider.'/unlink',static function(Request $request)use($oauth,$provider,$auth):Response{$context=$request->attributes['auth'];$oauth->unlink($request,$context,$provider);return (new Response(['message'=>'Provider unlinked. Sign in again.','redirect'=>'/login']))->withCookie($auth->logout($context));})->middleware($session);
            }
            $router->get('/mfa-challenge',static fn(Request $request):Response=>Response::html($view->render('auth.challenge',['csrf'=>$request->attributes['auth']->csrf])))->middleware($session);
            $router->post('/auth/oauth/mfa',static function(Request $request)use($oauth):Response{$fresh=$oauth->finishMfa($request,$request->attributes['auth']);return (new Response(['message'=>'Signed in.','redirect'=>'/dashboard']))->withCookie($fresh->cookie());})->middleware($session);
        }
    }
}
