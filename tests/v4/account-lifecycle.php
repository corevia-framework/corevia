<?php
declare(strict_types=1);
namespace CoreviaV4AccountLifecycle;
require dirname(__DIR__,2).'/autoload.php';require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{AccountDataAdapter,AccountLifecycle,AccountRecovery,AuthFailure,AuthService,DataRetentionHold,IdentityChange,IdentityStore,JsonAuthStore,SqlAuthStore};
use Corevia\Http\Request;
use Corevia\Persistence\SqlConnection;
use Corevia\Testing\{Clock,FakeMail};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Account lifecycle contract failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null&&(!$error instanceof AuthFailure||$error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('Unsafe lifecycle operation accepted.');}
function request(array $body):Request{return new Request('POST','/fixture',['content-type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR));}
final class OwnedData implements AccountDataAdapter
{
    public array $rows=[];public bool $held=false;public bool $failAfter=false;public array $seen=[];public int $effects=0;public string $version='1';public ?\Closure $duringErase=null;
    public function __construct(private string $label){}
    public function name():string{return $this->label;}
    public function revision():string{return $this->version;}
    public function export(string $userId):array{return $this->rows[$userId]??[];}
    public function assertDeletable(string $userId):void{if($this->held){throw new DataRetentionHold('Private operator-only legal hold reason.');}}
    public function erase(string $userId,string $operationId):void
    {
        $this->assertDeletable($userId);if(!isset($this->seen[$operationId])){unset($this->rows[$userId]);$this->seen[$operationId]=true;$this->effects++;}
        if($this->duringErase!==null){$callback=$this->duringErase;$this->duringErase=null;$callback();}
        if($this->failAfter){$this->failAfter=false;throw new \RuntimeException('Simulated failure after committed external erase.');}
    }
}
function contract(IdentityStore $store,string $label):void
{
    $clock=new Clock(time());$auth=new AuthService($store,clock:$clock);$password='fixture-password-123';
    $register=fn($email)=>$auth->register(request(['name'=>'Fixture','email'=>$email,'password'=>$password,'password_confirmation'=>$password]),$auth->open(new Request('GET','/login')));
    $alice=$register('alice@example.test');$bob=$register('bob@example.test');$aliceId=$alice->user['id'];$bobId=$bob->user['id'];
    $notes=new OwnedData('a_notes');$files=new OwnedData('b_files');$notes->rows=[$aliceId=>['note'=>'private alpha'],$bobId=>['note'=>'private beta']];$files->rows=[$aliceId=>['file'=>'alpha.bin'],$bobId=>['file'=>'beta.bin']];
    $failAudit=false;$events=[];$audit=static function($event,$metadata,$eventId)use(&$failAudit,&$events):void{
        if($failAudit&&$event==='account.deletion.step'){$failAudit=false;throw new \RuntimeException('Audit temporarily unavailable.');}
        $value=json_encode([$event,$metadata],JSON_THROW_ON_ERROR);if(isset($events[$eventId])){check($events[$eventId]===$value);}$events[$eventId]=$value;
    };
    $life=new AccountLifecycle($store,$auth,[$files,$notes],str_repeat('l',32),$clock,$audit);
    $confirm=request(['current_password'=>$password]);$delete=request(['current_password'=>$password,'confirmation'=>'DELETE']);
    $export=$life->export($confirm,$alice);check($export['identity']['id']===$aliceId&&$export['application']['a_notes']===['note'=>'private alpha']);$encoded=json_encode($export);check(!str_contains($encoded,'password_hash')&&!str_contains($encoded,$alice->digest)&&!str_contains($encoded,'private beta'));
    rejects(fn()=>$life->requestDeletion(request(['current_password'=>'wrong','confirmation'=>'DELETE']),$alice),'CREDENTIALS');
    $files->held=true;$operation=$life->requestDeletion($delete,$alice)['operation_id'];check($life->pending($aliceId)===$operation);check($store->authority($aliceId,$alice->digest,$clock())===null);
    rejects(fn()=>$life->export($confirm,$alice),'UNAUTHENTICATED');$user=$store->userById($aliceId);
    rejects(fn()=>$store->saveSession(bin2hex(random_bytes(32)),['user_id'=>$aliceId,'user_version'=>$user['auth_version'],'csrf'=>bin2hex(random_bytes(32)),'expires'=>$clock()+600,'mfa_verified'=>true],$clock()),'ACCOUNT_UNAVAILABLE');
    rejects(fn()=>$auth->login(request(['email'=>'alice@example.test','password'=>$password]),$auth->open(new Request('GET','/login'))),'ACCOUNT_UNAVAILABLE');
    $mail=new FakeMail();(new AccountRecovery($store,$mail,$auth,clock:$clock))->requestReset(request(['email'=>'alice@example.test']));check($mail->messages()===[]);
    check(!$store->issueAccountToken($aliceId,'reset',bin2hex(random_bytes(32)),$clock()+600,$user['auth_version'],$clock()));
    check($life->work($aliceId,$operation)['status']==='held'&&$notes->effects===0&&$files->effects===0);check($life->status($aliceId,$operation)['status']==='held');
    $life->cancel($aliceId,$operation);check($life->status($aliceId,$operation)['status']==='cancelled');$files->held=false;
    $alice=$auth->login(request(['email'=>'alice@example.test','password'=>$password]),$auth->open(new Request('GET','/login')));
    $operation=$life->requestDeletion($delete,$alice)['operation_id'];$failAudit=true;check($life->work($aliceId,$operation)['status']==='failed');check($notes->effects===1&&$files->effects===0);rejects(fn()=>$life->cancel($aliceId,$operation));
    $notes->version='2';rejects(fn()=>(new AccountLifecycle($store,$auth,[$notes,$files],str_repeat('l',32),$clock,$audit))->work($aliceId,$operation));$notes->version='1';
    $files->failAfter=true;check($life->work($aliceId,$operation)['status']==='failed');check($notes->effects===1&&$files->effects===1&&$store->userById($aliceId)!==null);
    check($life->work($aliceId,$operation)['status']==='complete');check($life->work($aliceId,$operation)['status']==='complete');check($notes->effects===1&&$files->effects===1&&$store->userById($aliceId)===null&&$notes->rows[$bobId]===['note'=>'private beta']);
    $receipt=json_encode($store->state('account-lifecycle:'.$operation));check(!str_contains($receipt,$aliceId)&&!str_contains($receipt,'alice@example.test'));rejects(fn()=>$life->status($bobId,$operation));
    // Missing identity is not accepted as proof that application data was erased.
    $bobOperation=$life->requestDeletion($delete,$bob)['operation_id'];$user=$store->userById($bobId);$store->changeIdentity($bobId,$user['auth_version'],static fn()=>new IdentityChange(null,true,true));rejects(fn()=>$life->work($bobId,$bobOperation));check(isset($notes->rows[$bobId]));
    // A worker whose lease expired cannot acknowledge effects. Its replacement safely replays the step ID.
    $charlie=$register('charlie@example.test');$id=$charlie->user['id'];$slow=new OwnedData('slow');$slow->rows[$id]=['private'=>'value'];$slow->duringErase=static fn()=>$clock->advance(301);$resumable=new AccountLifecycle($store,$auth,[$slow],str_repeat('l',32),$clock);
    $op=$resumable->requestDeletion($delete,$charlie)['operation_id'];rejects(fn()=>$resumable->work($id,$op));check($slow->effects===1&&$store->userById($id)!==null);check($resumable->work($id,$op)['status']==='complete'&&$slow->effects===1);
    echo 'PASS '.$label." account exports, auth/recovery shutdown, retention holds, cancellation, audit recovery, plan drift, uncertain erasure replay and worker fencing\n";
}
$directory=sys_get_temp_dir().'/corevia-v4-lifecycle-'.bin2hex(random_bytes(6));mkdir($directory);
try{contract(new JsonAuthStore($directory.'/auth.json'),'JSON');$sql=new SqlAuthStore(new SqlConnection(new \PDO('sqlite::memory:')));$sql->migrate();contract($sql,'SQLite');}
finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-lifecycle-');}

if(getenv('COREVIA_DB_TESTS')==='1'){
    require_once dirname(__DIR__).'/support/IdentityServices.php';
    \CoreviaTests\IdentityServices::run(static function($store,string $label):void{contract($store,$label);});
}
