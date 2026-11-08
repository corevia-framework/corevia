<?php
declare(strict_types=1);
namespace CoreviaV4Connected;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Ai\BudgetedProvider;
use Corevia\Core\TenantScope;
use Corevia\Persistence\{SqlConnection,SqlSyncStore,SqlUsageBudget};
use Corevia\Testing\FakeAi;
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Connected-client contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Unsafe connected operation accepted.');}
function contract(SqlConnection $db,string $prefix):void
{
$valid=true;$a=new TenantScope('alpha','alice',static function()use(&$valid):bool{return $valid;});$b=new TenantScope('beta','bob',fn()=>true);
$budget=new SqlUsageBudget($db,100,prefix:$prefix.'_budget');$budget->migrate();check($budget->reserve($a,60,1000)['remaining']===40);rejects(fn()=>$budget->reserve($a,50,1000));check($budget->reserve($b,100,1000)['remaining']===0);check($budget->reserve($a,40,1000)['remaining']===0);check($budget->reserve($a,100,86400)['remaining']===0);
$provider=new FakeAi(['hello']);$bounded=new BudgetedProvider($provider,$a,$budget,fn()=>2000);rejects(fn()=>iterator_to_array($bounded->stream([['role'=>'user','content'=>'hi']],20)));check($provider->calls()===[]);
$sync=new SqlSyncStore($db,str_repeat('s',32),$prefix.'_sync');$sync->migrate();$created=$sync->mutate($a,'one',0,['title'=>'first']);check($created['revision']===1);$sync->mutate($a,'two',0,['title'=>'second']);
$first=$sync->changes($a,null,1000,1);check(count($first['changes'])===1&&$first['has_more']);$second=$sync->changes($a,$first['cursor'],1001,1);check($second['changes'][0]['id']==='two'&&!$second['has_more']);
check($sync->changes($a,$second['cursor'],1002)['changes']===[]);rejects(fn()=>$sync->changes($b,$second['cursor'],1002));rejects(fn()=>$sync->changes(new TenantScope('alpha','other',fn()=>true),$second['cursor'],1002));rejects(fn()=>$sync->changes($a,$second['cursor'].'x',1002));rejects(fn()=>$sync->changes($a,$second['cursor'],100000));
rejects(fn()=>$sync->mutate($a,'one',0,['title'=>'lost update']));$sync->mutate($a,'one',1,null);$deleted=$sync->changes($a,$second['cursor'],1003);check($deleted['changes'][0]['deleted']&&$deleted['changes'][0]['revision']===2);
rejects(fn()=>$sync->mutate($a,'one',0,['title'=>'stale resurrection']));$sync->mutate($b,'one',0,['title'=>'separate tenant']);check(count($sync->changes($b,null,1004)['changes'])===1);
$db->pdo()->beginTransaction();try{
    $db->execute('DELETE FROM '.$db->quoteIdentifier($prefix.'_sync_events').' WHERE tenant=? AND sequence>?',[$a->namespace('sync'),2]);
    try{$sync->changes($a,$deleted['cursor'],1004);throw new \LogicException('Future cursor accepted after data restore.');}
    catch(\RuntimeException $error){check($error->getMessage()==='SYNC_CURSOR_REQUIRES_RESET');}
}finally{$db->pdo()->rollBack();}
$valid=false;rejects(fn()=>$sync->changes($a,$deleted['cursor'],1005));rejects(fn()=>$sync->mutate($a,'one',2,[]));rejects(fn()=>$budget->reserve($a,1,200000));
echo "PASS atomic tenant budgets, provider-before-call denial, resumable tenant/actor-bound cursors, conflicts, tombstones and revoked access\n";

}
contract(new SqlConnection(new \PDO('sqlite::memory:')),'cv_connected');
if(getenv('COREVIA_DB_TESTS')==='1'){
    require_once dirname(__DIR__).'/support/Services.php';
    foreach(\CoreviaTests\Services::drivers() as $driver){
        $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_v4connect_'.bin2hex(random_bytes(5));
        try{contract($db,$prefix);echo "PASS $driver usage/sync contract\n";}
        finally{foreach(['budget_buckets','budget_mutex','sync_events','sync_records','sync_mutex','sync_operations'] as $table){$db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table));}}
    }
}
