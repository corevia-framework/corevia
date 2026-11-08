<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('COREVIA_PASSKEY_FIXTURE')!=='1'){http_response_code(404);exit;}
$root=dirname(__DIR__,2);$directory=realpath(getenv('COREVIA_PASSKEY_STORAGE')?:'');
if($directory===false||dirname($directory)!==realpath($root.'/storage')||!str_starts_with(basename($directory),'v4-passkey-browser-')){throw new LogicException('Use an isolated passkey fixture directory.');}
require getenv('COREVIA_PASSKEY_AUTOLOAD')?:__DIR__.'/passkey-dependencies/vendor/autoload.php';require $root.'/autoload.php';
use Corevia\Auth\{AccountManager,AuthRoutes,AuthService,JsonAuthStore,Passkeys,PasskeyRoutes,SqlAuthStore,WebAuthnVerifier};
use Corevia\Core\Corevia;
use Corevia\Http\{Request,Response,Router,View};
use Corevia\Persistence\SqlConnection;
$store=getenv('COREVIA_PASSKEY_SQL')==='1'?new SqlAuthStore(new SqlConnection(new PDO('sqlite:'.$directory.'/auth.sqlite'))):new JsonAuthStore($directory.'/auth.json');if($store instanceof SqlAuthStore){$store->migrate();}
$origin=getenv('APP_URL');$auth=new AuthService($store,$origin);$passkeys=new Passkeys($store,$auth,new WebAuthnVerifier($origin));$app=Corevia::create($directory);$router=new Router($app,[$origin]);
$router->get('/',fn()=>Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Passkey protocol fixture</title><h1>Passkey protocol fixture</h1></html>'));
AuthRoutes::mount($router,new View($directory.'/views'),$auth,'bootstrap',manager:new AccountManager($store,$auth));PasskeyRoutes::mount($router,$auth,$passkeys);$app->boot();
try{$router->dispatch(Request::fromGlobals())->send();}finally{$app->shutdown();}
