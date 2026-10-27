<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Corevia,Module,ModuleContext};
use Corevia\Http\{Request,Router};
use Corevia\Protocol\{Protocol,RemoteAction,RemoteActionBinding};
$app=Corevia::create(__DIR__);
$remote=new RemoteAction(getenv('V2_WORKER_URL').'/cvp','test-only-token',0.2,version:Protocol::V2);
$app->container->set(RemoteActionBinding::class,new RemoteActionBinding($remote,'echo'));
$app->modules([new class implements Module {
    public function name():string{return 'bridge';} public function dependencies():array{return [];}
    public function register(ModuleContext $context):void{$context->actions(['bridge.echo'=>RemoteActionBinding::class]);}
}]);
try {
    $app->boot(); $router=new Router($app);
    $router->actionGateway(['bridge.echo'],static fn(Request $r):bool=>hash_equals('Bearer test-only-token',$r->header('authorization')));
    $router->dispatch(Request::fromGlobals())->send();
} finally {$app->shutdown();}
