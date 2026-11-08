<?php
declare(strict_types=1);
namespace CoreviaV4PasskeyStorage;
require dirname(__DIR__,2).'/autoload.php';require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{AuthFailure,IdentityChange,JsonAuthStore,PasskeyStore,SnapshotAuthStore,SqlAuthStore};
use Corevia\Core\CanonicalJson;
use Corevia\Persistence\{SnapshotStore,SqlConnection};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Passkey storage contract failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null&&(!$error instanceof AuthFailure||$error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('Unsafe passkey mutation accepted.');}
final class MemorySnapshot implements SnapshotStore
{
    private int $version=0;private ?string $payload=null;public int $conflicts=0;
    public function read():array{return ['version'=>$this->version,'payload'=>$this->payload];}
    public function compareExchange(int $version,string $payload):bool{if($this->conflicts>0){$this->conflicts--;$this->version++;return false;}if($version!==$this->version){return false;}$this->version++;$this->payload=$payload;return true;}
}
/** Storage-only fixtures are not cryptographic credentials; real verification is tested in Chrome. */
function contract(PasskeyStore $store,string $label):void
{
    $hash=password_hash('fixture-password-123',PASSWORD_BCRYPT,['cost'=>4]);$a=$store->createUser('a@example.test','A',$hash);$b=$store->createUser('b@example.test','B',$hash);$bytes=random_bytes(32);$digest=hash('sha256',$bytes);$b64=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
    $record=['label'=>'Fixture only','created'=>time(),'source'=>['publicKeyCredentialId'=>$b64($bytes),'credentialPublicKey'=>$b64('not-a-real-key'),'userHandle'=>$b64(hex2bin($a['id'])),'counter'=>0]];
    $session=bin2hex(random_bytes(32));$store->saveSession($session,['user_id'=>$a['id'],'user_version'=>0,'csrf'=>bin2hex(random_bytes(32)),'expires'=>time()+600],time());
    $user=$store->changePasskey($a['id'],0,$digest,$record);check($user['auth_version']===1&&$store->session($session,time())===null);check($store->state('pkey:'.$digest)['user_id']===$a['id']);
    $other=$record;$other['source']['userHandle']=$b64(hex2bin($b['id']));rejects(fn()=>$store->changePasskey($b['id'],0,$digest,$other),'PASSKEY_CONFLICT');rejects(fn()=>$store->changeState('pkey:'.$digest,static function(array &$state):void{$state=[];}));
    $updated=$record;$updated['source']['counter']=1;$expected=hash('sha256',CanonicalJson::encode($record));$user=$store->changePasskey($a['id'],1,$digest,$updated,$expected);check($user['auth_version']===1);
    rejects(fn()=>$store->changePasskey($a['id'],1,$digest,$updated,$expected),'PASSKEY_CHANGED');$expected=hash('sha256',CanonicalJson::encode($updated));$tampered=$updated;$tampered['source']['credentialPublicKey']=$b64('replacement-key');$tampered['source']['counter']=2;
    rejects(fn()=>$store->changePasskey($a['id'],1,$digest,$tampered,$expected),'PASSKEY_CHANGED');rejects(fn()=>$store->changePasskey($b['id'],0,$digest,null),'NOT_FOUND');
    $user=$store->changePasskey($a['id'],1,$digest,null);check($user['auth_version']===2&&$store->state('pkey:'.$digest)===[]);$user=$store->changePasskey($a['id'],2,$digest,$record);
    $store->changeIdentity($a['id'],$user['auth_version'],static fn()=>new IdentityChange(null,true,true));check($store->state('pkey:'.$digest)===[]&&$store->userById($b['id'])!==null);
    echo 'PASS '.$label." passkey uniqueness/ownership, credential-version revocation, assertion CAS, key-material protection and deletion cleanup\n";
}
$directory=sys_get_temp_dir().'/corevia-v4-passkey-storage-'.bin2hex(random_bytes(6));mkdir($directory);
try{contract(new JsonAuthStore($directory.'/auth.json'),'JSON');$sql=new SqlAuthStore(new SqlConnection(new \PDO('sqlite::memory:')));$sql->migrate();contract($sql,'SQLite');$memory=new MemorySnapshot();$memory->conflicts=2;contract(new SnapshotAuthStore($memory),'NoSQL CAS fixture');}
finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-passkey-storage-');}
