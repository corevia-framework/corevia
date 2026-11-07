<?php
declare(strict_types=1);
namespace CoreviaV4Reliability;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\Deadline;
use Corevia\Http\WebhookSignature;
use Corevia\Persistence\{SqlConnection,SqlOutbox};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Reliability contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Unsafe operation accepted.');}
$db=new SqlConnection(new \PDO('sqlite::memory:'));$db->execute('CREATE TABLE orders (id VARCHAR(32) PRIMARY KEY)');$outbox=new SqlOutbox($db);$outbox->migrate();
rejects(fn()=>$outbox->commit('order.created',[],static function($db):void{$db->table('orders')->insert(['id'=>'rollback']);throw new \RuntimeException('abort');},1000));
check($db->table('orders')->count()===0&&array_sum($outbox->status())===0);
$saved=$outbox->commit('order.created',['id'=>'one'],static function($db):string{$db->table('orders')->insert(['id'=>'one']);return 'saved';},1000);
check($saved['result']==='saved'&&$outbox->status()['pending']===1);
$deliveries=[];$outbox->relay(static function($event)use(&$deliveries):void{$deliveries[]=$event['event_id'];throw new \RuntimeException('unknown delivery');},1000);
check(!$outbox->relay(fn()=>true,1004));$outbox->relay(static function($event)use(&$deliveries):void{$deliveries[]=$event['event_id'];},1005);
check($deliveries===[$saved['event_id'],$saved['event_id']]&&$outbox->status()['done']===1);
// Simulate a worker crash after claiming. A newer worker reclaims; the expired worker cannot ack it.
$crashed=$outbox->commit('order.created',[],fn()=>null,2000);$db->execute('UPDATE cv_app_outbox SET status=?,owner=?,lease=?,attempt=1 WHERE id=?',['running',str_repeat('a',32),2010,$crashed['event_id']]);
check(!$outbox->relay(fn()=>true,2009));check($outbox->relay(fn()=>true,2010));check($outbox->status()['done']===2);
$outbox->commit('order.created',[],fn()=>null,2500);
$outbox->relay(static function()use($outbox):void{
    check($outbox->relay(fn()=>null,2510));
    throw new \RuntimeException('Old relay resumed after replacement completed.');
},2500,10);
check($outbox->status()['done']===3&&$outbox->status()['pending']===0);
$keys=['old'=>str_repeat('o',32),'new'=>str_repeat('n',32)];$old=new WebhookSignature($keys,'old');$current=new WebhookSignature($keys,'new');$id=bin2hex(random_bytes(16));$header=$old->sign('{"event":"ok"}',$id,3000);$seen=[];
$consume=static function($key,$expires)use(&$seen):bool{if(isset($seen[$key])){return false;}$seen[$key]=$expires;return true;};
check($current->verify('{"event":"ok"}',$header,3001,$consume)===$id);rejects(fn()=>$current->verify('{"event":"ok"}',$header,3001,$consume));rejects(fn()=>$current->verify('tampered',$header,3001,$consume));rejects(fn()=>$current->verify('{"event":"ok"}',$header,4000,$consume));
rejects(fn()=>(new WebhookSignature(['new'=>$keys['new']],'new'))->verify('{"event":"ok"}',$header,3001,$consume));
$tick=0;$deadline=new Deadline(100,static function()use(&$tick):int{return $tick;});check($deadline->remainingMilliseconds()===100);$tick=100000000;rejects(fn()=>$deadline->check());$cancel=new Deadline(100);$cancel->cancel();rejects(fn()=>$cancel->check());
echo "PASS transactional outbox rollback/retry/crash recovery, signed webhook rotation/replay and cooperative deadlines\n";
