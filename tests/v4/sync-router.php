<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Corevia,JsonLogger,TenantScope};
use Corevia\Http\{Request,Router,SyncRoutes};
use Corevia\Persistence\{SqlConnection,SqlSyncStore};
$root=realpath(dirname(__DIR__,2).'/storage');$directory=realpath(PHP_SAPI==='cli'?($argv[2]??''):(getenv('COREVIA_TEST_SYNC_DIRECTORY')?:''));
if ($directory===false||dirname($directory)!==$root||!str_starts_with(basename($directory),'sync-http-')) { throw new LogicException('Use only the private sync HTTP fixture directory.'); }
$store=new SqlSyncStore(SqlConnection::connect(['driver'=>'sqlite','database'=>$directory.'/sync.sqlite']),str_repeat('s',32));
if (PHP_SAPI==='cli'&&($argv[1]??'')==='prepare') { $store->migrate();echo "prepared\n";exit; }
if (PHP_SAPI!=='cli-server'||getenv('COREVIA_TEST_SYNC_HTTP')!=='1') { http_response_code(404);exit; }
$app=Corevia::create(dirname(__DIR__,2),logger:new JsonLogger($directory.'/runtime.log'));$app->boot();$router=new Router($app);
SyncRoutes::mount($router,$store,static function(Request $request):TenantScope {
    $token=$request->header('authorization');
    return match($token) {
        'Bearer alpha-test'=>new TenantScope('alpha','alice',fn()=>true,['sync.read','sync.write']),
        'Bearer beta-test'=>new TenantScope('beta','bob',fn()=>true,['sync.read','sync.write']),
        default=>throw new RuntimeException('No current fixture identity.'),
    };
});
try { $router->dispatch(Request::fromGlobals())->send(); } finally { $app->shutdown(); }
