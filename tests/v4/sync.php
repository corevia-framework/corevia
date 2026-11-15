<?php
declare(strict_types=1);
namespace CoreviaV4Sync;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Core\{Corevia,TenantScope};
use Corevia\Http\{Request,Router,SyncRoutes};
use Corevia\Persistence\{SqlConnection,SqlSyncStore};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Offline sync server assertion failed.'); } }
function rejects(callable $work,?string $code=null):void { try { $work(); } catch (\Throwable $error) { if ($code!==null&&$error->getMessage()!==$code) { throw $error; }return; }throw new \RuntimeException('Unsafe sync accepted.'); }
function contract(SqlConnection $db,string $prefix):void
{
    $store=new SqlSyncStore($db,str_repeat('k',32),$prefix);$store->migrate();$store->migrate();
    $valid=true;$a=new TenantScope('alpha','alice',static function()use(&$valid):bool { return $valid; },['sync.read','sync.write']);$b=new TenantScope('beta','bob',fn()=>true,['sync.read','sync.write']);
    $operation=bin2hex(random_bytes(16));$first=$store->mutateOnce($a,$operation,'note',0,['title'=>'original']);
    check($first['revision']===1&&$first['sequence']===1&&$first['operation_id']===$operation);
    check($store->mutateOnce($a,$operation,'note',0,['title'=>'original'])===$first);
    rejects(fn()=>$store->mutateOnce($a,$operation,'note',0,['title'=>'changed']),'SYNC_OPERATION_CONFLICT');
    $store->mutateOnce($a,bin2hex(random_bytes(16)),'note',1,null);check($store->mutateOnce($a,$operation,'note',0,['title'=>'original'])===$first);
    check(count($store->changes($a,null,time())['changes'])===2);$store->mutateOnce($b,$operation,'note',0,['title'=>'beta secret']);
    check(!str_contains(json_encode($store->changes($a,null,time())),'beta secret'));
    $rollback=bin2hex(random_bytes(16));rejects(fn()=>$db->transaction(static function()use($store,$a,$rollback):void { $store->mutateOnce($a,$rollback,'rolled-back',0,['title'=>'private']);throw new \RuntimeException('rollback'); }));
    check($store->mutateOnce($a,$rollback,'rolled-back',0,['title'=>'private'])['revision']===1);
    rejects(fn()=>$store->mutateOnce($a,'invalid','record',0,[]));rejects(fn()=>$store->mutateOnce($a,bin2hex(random_bytes(16)),'record',9007199254740991,[]));
    $app=Corevia::create(dirname(__DIR__,2));$app->boot();$router=new Router($app);
    SyncRoutes::mount($router,$store,static function($r)use($a):TenantScope { if ($r->header('authorization')!=='Bearer fixture') { throw new \RuntimeException('No authority.'); }return $a; });
    try {
        $request=static fn(array $data,array $headers=[])=>new Request('POST','/api/v1/sync/mutations',['content-type'=>'application/json',...$headers],json_encode($data,JSON_THROW_ON_ERROR));
        $input=['operation_id'=>bin2hex(random_bytes(16)),'id'=>'http-note','expected_revision'=>0,'data'=>['title'=>'safe']];
        check($router->dispatch($request($input))->status===403);$headers=['authorization'=>'Bearer fixture'];
        $one=$router->dispatch($request($input,$headers));check($one->status===200&&$router->dispatch($request($input,$headers))->body===$one->body);
        check($router->dispatch($request([...$input,'data'=>['title'=>'mutated']],$headers))->status===409);
        check($router->dispatch($request([...$input,'tenant'=>'beta'],$headers))->status===422);
        $feed=$router->dispatch(new Request('GET','/api/v1/sync/changes',$headers));check($feed->status===200&&isset($feed->body['data']['cursor']));
        check($router->dispatch(new Request('GET','/api/v1/sync/changes',$headers,attributes:['query'=>['cursor'=>'bad']]))->status===409);
        $valid=false;check($router->dispatch($request($input,$headers))->status===403);rejects(fn()=>$store->mutateOnce($a,$operation,'note',0,['title'=>'original']));
    } finally { $app->shutdown(); }
    echo 'PASS '.$db->driver()." atomic actor/tenant retry receipts, lost-ack replay, changed-input conflict, rollback, API authorization/revocation and cursor reset\n";
}
contract(new SqlConnection(new \PDO('sqlite::memory:')),'cv_sync_test');
if (getenv('COREVIA_DB_TESTS')==='1') {
    foreach (\CoreviaTests\Services::drivers() as $driver) {
        $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_sync_'.bin2hex(random_bytes(5));
        try { contract($db,$prefix); }
        finally { foreach (['operations','events','records','mutex'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
    }
}
