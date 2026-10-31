<?php
declare(strict_types=1);
namespace CoreviaMailTests;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Auth\{AccountMessage,MailQueue,MailTransport,SecretBox,SmtpTransport};
use Corevia\Core\ScopeManager;
function check(bool $value):void{if(!$value){throw new \RuntimeException('Mail queue contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected mail rejection.');}
if(!function_exists('openssl_encrypt')){echo "SKIP encrypted mail queue/TLS: OpenSSL unavailable; base JSON auth remains usable\n";return;}
final class Transport implements MailTransport
{
    public bool $fail=false;public array $sent=[];public ?\Closure $during=null;
    public function send(string $id,AccountMessage $message):void{if($this->during!==null){($this->during)();}if($this->fail){throw new \RuntimeException('private SMTP diagnostic must not persist');}$this->sent[]=[$id,$message->recipient,$message->link()];}
}
$directory=sys_get_temp_dir().'/corevia-mail-'.bin2hex(random_bytes(6));mkdir($directory);
try{
    $now=time();$clock=static function()use(&$now):int{return $now;};$box=new SecretBox(random_bytes(32));$transport=new Transport();$scopes=new ScopeManager();$closed=0;$queue=new MailQueue($directory.'/queue.json',$box,$transport,$clock,$scopes);$message=new AccountMessage('private@example.test','reset',$now+10000,'https://example.test/reset-password#token='.str_repeat('a',64));
    $queue->deliver($message);$raw=file_get_contents($directory.'/queue.json');check(!str_contains($raw,$message->recipient)&&!str_contains($raw,$message->link())&&!str_contains($raw,str_repeat('a',64)));check($queue->status()===['ready'=>1,'leased'=>0,'failed'=>0]);
    // The transport runs outside the spool lock; another worker cannot claim the live lease.
    $transport->during=static function()use($queue,$scopes,&$closed):void{check($queue->status()['leased']===1 && $queue->workOnce()===['status'=>'empty']);check($scopes->current()?->kind==='mail.job');$scopes->current()->onClose(static function()use(&$closed):void{$closed++;});};$result=$queue->workOnce();check($result['status']==='sent'&&count($transport->sent)===1&&$queue->workOnce()['status']==='empty'&&$closed===1&&$scopes->activeCount()===0);$transport->during=null;
    $transport->fail=true;$queue->deliver($message);for($attempt=1;$attempt<=6;$attempt++){$result=$queue->workOnce();check($result['status']===($attempt===6?'failed':'retry'));check(!str_contains(file_get_contents($directory.'/queue.json'),'private SMTP diagnostic'));check($queue->workOnce()['status']==='empty');$now+=4000;if($attempt<6){$data=json_decode(file_get_contents($directory.'/queue.json'),true);foreach($data['jobs'] as &$job){$job['expires']=$now+10000;}unset($job);file_put_contents($directory.'/queue.json',json_encode($data));}}
    check($queue->status()['failed']===1);
    // Simulate a crashed worker: its lease must expire before retry can claim it.
    $transport->fail=false;$queue->deliver(new AccountMessage('retry@example.test','verify',$now+1000,'https://example.test/verify-email#token=fixture'));
    $data=json_decode(file_get_contents($directory.'/queue.json'),true);foreach($data['jobs'] as &$job){if(!$job['failed']){$job['lease']=$now+120;$job['lease_id']=str_repeat('a',32);}}unset($job);file_put_contents($directory.'/queue.json',json_encode($data));check($queue->workOnce()['status']==='empty');$now+=121;check($queue->workOnce()['status']==='sent');$now+=20000;check($queue->status()===['ready'=>0,'leased'=>0,'failed'=>0]);
    file_put_contents($directory.'/queue.json','{corrupt');rejects(fn()=>$queue->status());check(file_get_contents($directory.'/queue.json')==='{corrupt');
    $invalid=json_encode(['format'=>1,'jobs'=>['bad-record'=>['payload'=>'incomplete']]]);file_put_contents($directory.'/queue.json',$invalid);rejects(fn()=>$queue->status());check(file_get_contents($directory.'/queue.json')===$invalid);
    rejects(fn()=>new SmtpTransport(['host'=>'smtp.example.test','port'=>25,'encryption'=>'none','from'=>'sender@example.test']));rejects(fn()=>new SmtpTransport(['host'=>'smtp.example.test','port'=>587,'encryption'=>'starttls','from'=>"sender@example.test\r\nBcc: private@example.test"]));
    echo "PASS encrypted private mail spool, single-worker leases, crash recovery, bounded retries, expiry, corruption preservation and SMTP configuration guards\n";
}finally{foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
