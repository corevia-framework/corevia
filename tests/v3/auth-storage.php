<?php
declare(strict_types=1);
namespace CoreviaAuthStorageTests;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Auth\{AccountStore,AuthFailure,JsonAuthStore,SqlAuthStore};
use Corevia\Persistence\SqlConnection;
function check(bool $value):void{if(!$value){throw new \RuntimeException('Auth storage contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected auth-store rejection.');}
function concurrentToken(AccountStore $store,string $path,bool $sql):void{
    $user=$store->findUser('ada@example.test');$digest=hash('sha256','parallel-token-test');check($store->issueAccountToken($user['id'],'reset',$digest,2000,$user['auth_version'],1000));
    $program='require $argv[1]; $store=$argv[2]==="sql"?new Corevia\\Auth\\SqlAuthStore(Corevia\\Persistence\\SqlConnection::connect(["driver"=>"sqlite","database"=>$argv[3],"options"=>[PDO::ATTR_TIMEOUT=>10]])):new Corevia\\Auth\\JsonAuthStore($argv[3]); echo json_encode($store->completeAccountToken($argv[4],"reset",1000,$argv[5]));';$children=[];
    foreach([1,2] as $index){$pipes=[];$command=[PHP_BINARY,...($sql?[]:['-n']),'-r',$program,dirname(__DIR__,2).'/autoload.php',$sql?'sql':'json',$path,$digest,$user['password_hash']];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process));fclose($pipes[0]);$children[]=[$process,$pipes];}
    $created=0;$valid=true;foreach($children as [$process,$pipes]){$created+=json_decode(stream_get_contents($pipes[1]),true)===true?1:0;$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$valid=($exit===0 && $stderr==='') && $valid;}check($valid && $created===1);
}
function contract(AccountStore $store):void{
    $hash=password_hash('example-password-123',PASSWORD_BCRYPT,['cost'=>4]);$user=$store->createUser('ada@example.test','Ada',$hash);$other=$store->createUser('other@example.test','Other',$hash);
    check($user!==null && $store->createUser('ada@example.test','Duplicate',$hash)===null);check($store->findUser('ada@example.test')['id']===$user['id']);
    check(!$store->issueAccountToken($user['id'],'verify','recipient-race',1900,0,1000,'stale-address@example.test'));check($store->accountToken('recipient-race','verify',1000)===null);
    $session=['user_id'=>$user['id'],'user_version'=>0,'csrf'=>str_repeat('a',64),'expires'=>2000];$store->saveSession('session-one',$session,1000);$store->saveSession('session-two',$session,1000);$store->saveSession('other-session',[...$session,'user_id'=>$other['id']],1000);
    check($store->session('session-one',1000)!==null);$store->saveSession('expired-session',[...$session,'expires'=>1000],999);check($store->session('expired-session',1000)===null);
    for($i=0;$i<3;$i++){check($store->consume([['limit',3,60],['global',5,60]],1000));}check(!$store->consume([['limit',3,60],['global',5,60]],1000));check($store->consume([['global',5,60]],1000));check($store->consume([['global',5,60]],1000));check(!$store->consume([['global',5,60]],1000));check($store->consume([['limit',3,60]],1060));
    check($store->issueAccountToken($user['id'],'verify','verify-old',1900,0,1000));check($store->issueAccountToken($user['id'],'verify','verify-new',1900,0,1000));check($store->accountToken('verify-old','verify',1000)===null);
    check($store->accountToken('verify-new','reset',1000)===null);check(!$store->completeAccountToken('verify-new','verify',1000,requiredUserId:$other['id']));check($store->completeAccountToken('verify-new','verify',1000,requiredUserId:$user['id']));check(!$store->completeAccountToken('verify-new','verify',1000,requiredUserId:$user['id']));check($store->userById($user['id'])['email_verified_at']===1000);
    check($store->issueAccountToken($user['id'],'reset','expired',1100,0,1000));check(!$store->completeAccountToken('expired','reset',1100,$hash));
    check($store->issueAccountToken($user['id'],'reset','reset',1900,0,1000));check($store->issueAccountToken($user['id'],'verify','verify-pending',1900,0,1000));$newHash=password_hash('changed-password-456',PASSWORD_BCRYPT,['cost'=>4]);
    check($store->completeAccountToken('reset','reset',1001,$newHash));check(!$store->completeAccountToken('reset','reset',1001,$newHash));check($store->session('session-one',1001)===null && $store->session('session-two',1001)===null);check($store->session('other-session',1001)!==null);
    check($store->accountToken('verify-pending','verify',1001)===null);check($store->userById($user['id'])['auth_version']===1);check(password_verify('changed-password-456',$store->findUser('ada@example.test')['password_hash']));
    rejects(fn()=>$store->saveSession('stale-login',$session,1001));check($store->session('stale-login',1001)===null);check(!$store->issueAccountToken($user['id'],'reset','stale-token',1900,0,1001));
    $store->saveSession('fresh-login',[...$session,'user_version'=>1],1001);check($store->session('fresh-login',1001)['user_version']===1);$store->deleteSession('fresh-login');check($store->session('fresh-login',1001)===null);
}
$directory=sys_get_temp_dir().'/corevia-auth-store-'.bin2hex(random_bytes(6));mkdir($directory);
try{
    $json=new JsonAuthStore($directory.'/auth.json');contract($json);concurrentToken($json,$directory.'/auth.json',false);echo "PASS JSON auth/account storage and concurrent single-use reset\n";
    if(class_exists(\PDO::class) && in_array('sqlite',\PDO::getAvailableDrivers(),true)){
        $db=SqlConnection::connect(['driver'=>'sqlite','database'=>$directory.'/auth.sqlite']);$store=new SqlAuthStore($db);$store->migrate();$store->migrate();contract($store);concurrentToken($store,$directory.'/auth.sqlite',true);rejects(fn()=>$db->transaction(fn()=>$store->findUser('ada@example.test')));echo "PASS SQLite auth/account storage, concurrent single use, idempotent schema and transaction ownership\n";
    }else{echo "SKIP SQL auth: no SQLite PDO driver\n";}
    if(getenv('COREVIA_DB_TESTS')==='1'){
        foreach(['mysql','pgsql'] as $driver){
            $db=SqlConnection::connect(['driver'=>$driver,'host'=>'127.0.0.1','port'=>$driver==='mysql'?3306:5432,'database'=>'corevia_ci','username'=>'corevia','password'=>'ci-only-password']);$prefix='cv_auth_test_'.bin2hex(random_bytes(5));
            try{$store=new SqlAuthStore($db,$prefix);$store->migrate();$store->migrate();contract($store);echo "PASS $driver auth/account storage contract\n";}
            finally{foreach(['state','tokens','sessions','limits','users','mutex'] as $name){$db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$name));}}
        }
    }
}finally{unset($store,$db);foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
