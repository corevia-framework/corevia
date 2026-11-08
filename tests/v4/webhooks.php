<?php
declare(strict_types=1);
namespace CoreviaV4Webhooks;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Http\{HttpClient,WebhookDelivery,WebhookSignature};
use Corevia\Persistence\{SqlConnection,SqlOutbox,SqlReplayReservations};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Webhook contract failed.'); } }
function rejects(callable $work):void { try { $work(); } catch (\Throwable) { return; }throw new \RuntimeException('Unsafe webhook operation accepted.'); }
function contract(SqlConnection $db,string $prefix):void
{
    $outbox=new SqlOutbox($db,$prefix.'_outbox');$outbox->migrate();$outbox->migrate();
    $replay=new SqlReplayReservations($db,$prefix.'_replay');$replay->migrate();
    $keys=['old'=>str_repeat('o',32),'new'=>str_repeat('n',32)];$old=new WebhookSignature($keys,'old');$new=new WebhookSignature($keys,'new');
    $http=new class implements HttpClient {
        public array $requests=[];public bool $fail=true;
        public function stream(string $method,string $url,array $headers=[],string $body=''):iterable {
            $this->requests[]=compact('url','headers','body');if ($this->fail) { throw new \RuntimeException('private-destination-error'); }yield '{}';
        }
    };
    rejects(fn()=>new WebhookDelivery(['order.created'=>['url'=>'http://public.example.test/webhook','signature'=>$old]],$http));
    rejects(fn()=>new WebhookDelivery(['order.created'=>['url'=>'https://example.test/hook?secret=value','signature'=>$old]],$http));
    $sender=new WebhookDelivery(['order.created'=>['url'=>'https://example.test/hook','signature'=>$old]],$http);
    $event=$outbox->commit('order.created',['number'=>1],fn()=>null,1000)['event_id'];
    check($sender->relay($outbox,1000,1)&&$outbox->status()['dead']===1);
    $history=$outbox->history($event);check(count($history)===1&&$history[0]['outcome']==='dead'&&!str_contains(json_encode($history),'private-destination-error'));
    $request=bin2hex(random_bytes(16));check(!$outbox->retryDead($event,2,$request,1001));
    check($outbox->retryDead($event,1,$request,1001)&&!$outbox->retryDead($event,1,$request,1001));
    $http->fail=false;$sender=new WebhookDelivery(['order.created'=>['url'=>'https://example.test/hook','signature'=>$new]],$http);
    check($sender->relay($outbox,1001,1)&&$outbox->status()['done']===1&&count($outbox->history($event))===2);
    check($http->requests[0]['body']===$http->requests[1]['body']&&$http->requests[1]['headers']['Idempotency-Key']===$event);
    check($new->verify($http->requests[0]['body'],$http->requests[0]['headers']['Corevia-Signature'],1001,fn($digest,$expires)=>$replay->reserve($digest,$expires,1001))===$event);
    rejects(fn()=>$new->verify($http->requests[1]['body'],$http->requests[1]['headers']['Corevia-Signature'],1001,fn($digest,$expires)=>$replay->reserve($digest,$expires,1001)));
    rejects(fn()=>$replay->reserve('bad',1100,1001));rejects(fn()=>$db->transaction(fn()=>$replay->reserve(hash('sha256','one'),1100,1001)));
    check($replay->prune(1001)===0&&$replay->prune(2000)===1);
    // Crash after durable attempt intent. A new claim retains the uncertain delivery in history.
    $crash=$outbox->commit('order.created',[],fn()=>null,2000)['event_id'];$owner=bin2hex(random_bytes(16));
    $db->execute('UPDATE '.$db->quoteIdentifier($prefix.'_outbox').' SET status=?,attempt=1,lease=?,owner=? WHERE id=?',['running',2010,$owner,$crash]);
    $db->execute('INSERT INTO '.$db->quoteIdentifier($prefix.'_outbox_attempts').' (delivery_id,event_id,attempt,started,finished,outcome,error_type) VALUES (?,?,?,?,NULL,?,NULL)',[$owner,$crash,1,2000,'running']);
    check($sender->relay($outbox,2011,2));$outcomes=array_column($outbox->history($crash),'outcome');check(in_array('uncertain',$outcomes,true)&&in_array('delivered',$outcomes,true));
    check(!$outbox->retryDead($crash,2,bin2hex(random_bytes(16)),2012));rejects(fn()=>$outbox->history($event,101));
    echo 'PASS '.$db->driver()." durable delivery intent/history, stable-ID controlled redelivery, uncertain crash recovery, signed key rotation and atomic replay reservations\n";
}
contract(new SqlConnection(new \PDO('sqlite::memory:')),'cv_hook');
if (getenv('COREVIA_DB_TESTS')==='1') {
    foreach (\CoreviaTests\Services::drivers() as $driver) {
        $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_hook_'.bin2hex(random_bytes(5));
        try { contract($db,$prefix); }
        finally { foreach (['outbox_attempts','outbox_operations','outbox','replay'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
    }
}
