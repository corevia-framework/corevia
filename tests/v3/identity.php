<?php
declare(strict_types=1);
namespace CoreviaIdentityTests;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Auth\{AccountManager,AuthContext,AuthFailure,AuthService,Authorize,Gate,IdentityChange,IdentityStore,JsonAuthStore,MfaService,OAuthProvider,OAuthService,ResourceIdentity,SecretBox,SqlAuthStore,Totp};
use Corevia\Http\{Request,Response};
use Corevia\Persistence\SqlConnection;
function check(bool $ok,string $message='Identity contract failed'):void{if(!$ok){throw new \RuntimeException($message);}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null){check($error instanceof AuthFailure && $error->errorCode===$code,'Unexpected failure: '.$error::class.' '.$error->getMessage());}return;}throw new \RuntimeException('Expected rejection.');}
function request(array $body=[]):Request{return new Request('POST','/auth/fixture',['content-type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR));}
function anonymous(AuthService $auth):AuthContext{return $auth->open(new Request('GET','/login'));}
function signin(AuthService $auth,string $email,string $password='example-password-123',?string $code=null):AuthContext{return $auth->login(request(['email'=>$email,'password'=>$password,'code'=>$code]),anonymous($auth));}
final class FixtureProvider implements OAuthProvider
{
    public string $verifier='';public int $calls=0;
    public function authorizationEndpoint():string{return 'https://provider.example/authorize';}
    public function clientId():string{return 'fixture-client';}
    public function scopes():array{return ['identity'];}
    public function identity(string $code,string $verifier,string $redirectUri):string{$this->calls++;check(strlen($verifier)===43 && str_ends_with($redirectUri,'/callback'));$this->verifier=$verifier;return $code==='different-subject'?'subject-2':'subject-1';}
}
function flow(OAuthService $oauth,AuthContext $context,Request $start,string $provider='example'):array{$url=$oauth->start($start,$context,$provider);parse_str(parse_url($url,PHP_URL_QUERY),$query);check($query['code_challenge_method']==='S256'&&$query['response_type']==='code');return $query;}
function callback(array $query):Request{return new Request('GET','/auth/oauth/example/callback',attributes:['query'=>['state'=>$query['state'],'code'=>$query['code']??'fixture-code']]);}
function parallel(string $program,array $arguments):array
{
    $children=[];foreach($arguments as $args){$pipes=[];$process=proc_open([PHP_BINARY,'-r',$program,dirname(__DIR__,2).'/autoload.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process));fclose($pipes[0]);$children[]=[$process,$pipes];}$results=[];
    foreach($children as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);check($exit===0&&$error==='','Concurrent worker failed: '.$error);$results[]=json_decode($output,true,32,JSON_THROW_ON_ERROR);}return $results;
}
function concurrent(IdentityStore $store,string $path,bool $sql):void
{
    $hash=password_hash('example-password-123',PASSWORD_BCRYPT,['cost'=>4]);$user=$store->createUser('parallel-factor@example.test','Parallel',$hash);$box=new SecretBox(str_repeat('F',32));$secret=Totp::secret();$code=str_repeat('b',20);
    $store->changeIdentity($user['id'],0,static function(array &$row,array &$security)use($box,$secret,$code):IdentityChange{$security['mfa']=['secret'=>$box->seal($secret,'mfa:'.$row['id']),'counter'=>-1,'recovery'=>[hash('sha256',$code)]];return new IdentityChange(null,true);});
    $setup='require $argv[1]; $store=$argv[2]==="sql"?new Corevia\\Auth\\SqlAuthStore(Corevia\\Persistence\\SqlConnection::connect(["driver"=>"sqlite","database"=>$argv[3],"options"=>[PDO::ATTR_TIMEOUT=>10]])):new Corevia\\Auth\\JsonAuthStore($argv[3]);';
    $program=$setup.' $mfa=new Corevia\\Auth\\MfaService($store,new Corevia\\Auth\\SecretBox(str_repeat("F",32))); $auth=new Corevia\\Auth\\AuthService($store,mfa:$mfa); $context=$auth->open(new Corevia\\Http\\Request("GET","/login")); try{$auth->login(new Corevia\\Http\\Request("POST","/auth/login",["content-type"=>"application/json"],json_encode(["email"=>"parallel-factor@example.test","password"=>"example-password-123","code"=>str_repeat("b",20)])),$context);echo "true";}catch(Corevia\\Auth\\AuthFailure $error){if($error->errorCode!=="MFA_INVALID")throw $error;echo "false";}';
    $args=[$sql?'sql':'json',$path];$results=parallel($program,[$args,$args]);check(count(array_filter($results))===1,'Recovery code had more than one concurrent winner');
    $first=$store->createUser('parallel-one@example.test','One',$hash);$second=$store->createUser('parallel-two@example.test','Two',$hash);$subject=hash('sha256','one-provider-subject');
    $program=$setup.' try{$store->changeProvider($argv[4],0,"fixture",$argv[5]);echo "true";}catch(Corevia\\Auth\\AuthFailure $error){if($error->errorCode!=="OAUTH_CONFLICT")throw $error;echo "false";}';$results=parallel($program,[[...$args,$first['id'],$subject],[...$args,$second['id'],$subject]]);check(count(array_filter($results))===1,'Provider subject linked to more than one account');$winner=$store->state('oid:'.$subject)['user_id'];
    foreach([$first,$second] as $row){$won=$winner===$row['id'];check($store->userById($row['id'])['auth_version']===($won?1:0));check(($store->state('user:'.$row['id'])['oauth']['fixture']??null)===($won?$subject:null));}
}
function contract(IdentityStore $store,bool $crypto):void
{
    $hash=password_hash('example-password-123',PASSWORD_BCRYPT,['cost'=>4]);$ada=$store->createUser('ada@example.test','Ada',$hash);$bob=$store->createUser('bob@example.test','Bob',$hash);$auth=new AuthService($store);$manager=new AccountManager($store,$auth);$ctx=signin($auth,$ada['email']);
    rejects(fn()=>$manager->update(request(['name'=>'Root','roles'=>['admin'],'current_password'=>'example-password-123']),$ctx,'profile'),'VALIDATION');
    rejects(fn()=>$manager->update(request(['name'=>'Root','current_password'=>'incorrect']),$ctx,'profile'),'CREDENTIALS');
    $result=$manager->update(request(['name'=>'Ada Updated','current_password'=>'example-password-123']),$ctx,'profile');check($result['user']['name']==='Ada Updated'&&$store->session($ctx->digest,time())===null);
    rejects(fn()=>$auth->confirm(request(['current_password'=>'example-password-123']),$ctx),'UNAUTHENTICATED');$ctx=$auth->replaceSession($ctx,$result['user'],true);
    rejects(fn()=>$manager->update(request(['email'=>$bob['email'],'current_password'=>'example-password-123']),$ctx,'email'),'ACCOUNT_CONFLICT');
    $store->issueAccountToken($ada['id'],'verify','old-email-token',time()+600,$result['user']['auth_version'],time());
    $result=$manager->update(request(['email'=>'updated@example.test','current_password'=>'example-password-123']),$ctx,'email');check($result['user']['email_verified_at']===null&&$store->findUser($ada['email'])===null&&$store->accountToken('old-email-token','verify',time())===null);$ctx=$auth->replaceSession($ctx,$result['user'],true);
    $result=$manager->update(request(['password'=>'changed-password-456','password_confirmation'=>'changed-password-456','current_password'=>'example-password-123']),$ctx,'password');$ctx=$auth->replaceSession($ctx,$result['user'],true);
    rejects(fn()=>signin($auth,'updated@example.test'),'CREDENTIALS');$other=signin($auth,'updated@example.test','changed-password-456');$bobContext=signin($auth,$bob['email']);
    $sessions=$manager->sessions($ctx);check(count($sessions)===2);foreach($sessions as $row){check($row['id']!==$ctx->digest && $row['id']!==$other->digest && array_keys($row)===['id','expires','current']);}
    $bobHandle=$manager->sessions($bobContext)[0]['id'];$manager->revoke(request(['current_password'=>'changed-password-456','session'=>$bobHandle]),$ctx);check($store->session($bobContext->digest,time())!==null);
    $manager->revoke(request(['current_password'=>'changed-password-456','session'=>'others']),$ctx);check($store->session($ctx->digest,time())!==null&&$store->session($other->digest,time())===null);
    $gate=new Gate($store,['admin'=>['admin.access'],'editor'=>['documents.read','documents.update']]);check(!$gate->allows($ctx,'admin.access'));$gate->assign($ada['id'],['admin']);check(!$gate->allows($ctx,'admin.access'));$ctx=signin($auth,'updated@example.test','changed-password-456');check($gate->allows($ctx,'admin.access')&&!$gate->allows($ctx,'anything.unknown'));
    $gate->assign($ada['id'],['editor'],'tenant-a');$ctx=signin($auth,'updated@example.test','changed-password-456');check($gate->allows($ctx,'documents.update',new ResourceIdentity('tenant-a',$ada['id']),true));check(!$gate->allows($ctx,'documents.update',new ResourceIdentity('tenant-b',$ada['id']),true));check(!$gate->allows($ctx,'documents.update',new ResourceIdentity('tenant-a',$bob['id']),true));
    check(!$gate->allows(new AuthContext($bobContext->digest,$bobContext->csrf,$ctx->user,null),'admin.access'));check($store->authority($ada['id'],$bobContext->digest,time())===null);
    $middleware=new Authorize($gate,'documents.read',static fn()=>new ResourceIdentity('tenant-a'));check($middleware->process((new Request('GET','/docs'))->withAttribute('auth',$ctx),static fn()=>new Response([]))->status===200);
    rejects(fn()=>(new Authorize($gate,'documents.read',static fn()=>null))->process((new Request('GET','/docs'))->withAttribute('auth',$ctx),static fn()=>new Response([])),'FORBIDDEN');
    $before=$store->userById($ada['id']);rejects(fn()=>$store->changeIdentity($ada['id'],$before['auth_version'],static function(array &$row,array &$state):IdentityChange{$row['name']='Bad';$state['roles']=['admin'];throw new \RuntimeException('Rollback fixture');}));check($store->userById($ada['id'])===$before);
    rejects(fn()=>$store->changeState('invalid/key',static function(array &$state):void{$state=['value'=>1];}));rejects(fn()=>$store->changeState('oversized',static function(array &$state):void{$state=['value'=>str_repeat('x',65537)];}));
    rejects(fn()=>$store->changeState('user:'.$ada['id'],static function(array &$state):void{$state['roles']=['admin'];}));rejects(fn()=>$store->changeState('oid:'.str_repeat('a',64),static function(array &$state):void{$state=['user_id'=>'forged'];}));
    rejects(fn()=>$store->changeIdentity($ada['id'],$before['auth_version'],static function(array &$row,array &$state):IdentityChange{$state['expires']=time()+1;return new IdentityChange();}));
    $gate->assign($ada['id'],[]);rejects(fn()=>$store->changeIdentity($ada['id'],$before['auth_version'],static fn()=>new IdentityChange()),'SESSION_CHANGED');
    if($crypto){
        $box=new SecretBox(random_bytes(32));$mfa=new MfaService($store,$box);$strong=new AuthService($store,mfa:$mfa);$user=$store->createUser('mfa@example.test','MFA',$hash);$context=signin($strong,$user['email']);$setup=$mfa->begin($user,$context,'Corevia');check(str_starts_with($setup['uri'],'otpauth://totp/'));
        rejects(fn()=>$mfa->enable($user,anonymous($strong),Totp::code($setup['secret'],time())),'MFA_SETUP_EXPIRED');$enrollmentCode=Totp::code($setup['secret'],time());$enabled=$mfa->enable($user,$context,$enrollmentCode);$codes=$enabled['value'];check(count(array_unique($codes))===8);
        $state=$store->state('user:'.$user['id']);check(!str_contains(json_encode($state),$setup['secret'])&&!str_contains(json_encode($state),$codes[0]));check($store->session($context->digest,time())===null);
        rejects(fn()=>$store->saveSession('password-only',['user_id'=>$user['id'],'user_version'=>$enabled['user']['auth_version'],'csrf'=>str_repeat('a',64),'expires'=>time()+600],time()),'MFA_REQUIRED');
        rejects(fn()=>signin($strong,$user['email']),'MFA_REQUIRED');rejects(fn()=>signin($auth,$user['email']),'MFA_UNAVAILABLE');rejects(fn()=>signin($strong,$user['email'],code:$enrollmentCode),'MFA_INVALID');
        $context=$strong->login(request(['email'=>$user['email'],'password'=>'example-password-123','code'=>$codes[0],'remember'=>'1']),anonymous($strong));check(str_contains($context->cookie(),'Max-Age=2592000'));rejects(fn()=>signin($strong,$user['email'],code:$codes[0]),'MFA_INVALID');
        $row=$store->userById($user['id']);check($store->issueAccountToken($user['id'],'reset','mfa-reset',time()+600,$row['auth_version'],time()));check($store->completeAccountToken('mfa-reset','reset',time(),$hash));rejects(fn()=>signin($strong,$user['email']),'MFA_REQUIRED');$context=signin($strong,$user['email'],code:$codes[1]);
        $confirmed=$strong->confirm(request(['current_password'=>'example-password-123','code'=>$codes[2]]),$context);$disabled=$mfa->disable($confirmed);check(!$mfa->enabled($user['id']));signin($strong,$user['email']);
        $provider=new FixtureProvider();$oauth=new OAuthService($store,$box,$strong,['example'=>$provider,'other'=>new FixtureProvider()]);$oauthUser=$store->createUser('oauth@example.test','OAuth',$hash);$linkedContext=signin($strong,$oauthUser['email']);$query=flow($oauth,$linkedContext,request(['current_password'=>'example-password-123']));
        rejects(fn()=>$oauth->callback(callback($query),anonymous($strong),'example'),'OAUTH_STATE');rejects(fn()=>$oauth->callback(callback($query),$linkedContext,'other'),'OAUTH_STATE');check($provider->calls===0);$linkedContext=$oauth->callback(callback($query),$linkedContext,'example');check($linkedContext->user['id']===$oauthUser['id']);check(rtrim(strtr(base64_encode(hash('sha256',$provider->verifier,true)),'+/','-_'),'=')===$query['code_challenge']);rejects(fn()=>$oauth->callback(callback($query),$linkedContext,'example'),'OAUTH_STATE');
        $anon=anonymous($strong);$query=flow($oauth,$anon,request());$signed=$oauth->callback(callback($query),$anon,'example');check($signed->user['id']===$oauthUser['id']);
        $anon=anonymous($strong);$query=flow($oauth,$anon,request());$query['code']='different-subject';rejects(fn()=>$oauth->callback(callback($query),$anon,'example'),'OAUTH_LINK_REQUIRED');
        $setup=$mfa->begin($store->userById($oauthUser['id']),$signed,'Corevia');$enabled=$mfa->enable($store->userById($oauthUser['id']),$signed,Totp::code($setup['secret'],time()));$anon=anonymous($strong);$query=flow($oauth,$anon,request());check($oauth->callback(callback($query),$anon,'example')===null&&$anon->user===null);rejects(fn()=>$oauth->finishMfa(request(),$anon),'MFA_REQUIRED');$signed=$oauth->finishMfa(request(['code'=>$enabled['value'][0]]),$anon);check($signed->user['id']===$oauthUser['id']);rejects(fn()=>$oauth->finishMfa(request(['code'=>$enabled['value'][1]]),$anon),'OAUTH_STATE');
        $oauth->unlink(request(['current_password'=>'example-password-123','code'=>$enabled['value'][1]]),$signed,'example');check($store->state('user:'.$oauthUser['id'])['oauth']===[]&&$store->session($signed->digest,time())===null);
    }else{
        $user=$store->createUser('unavailable@example.test','Unavailable',$hash);$store->changeIdentity($user['id'],0,static function(array &$row,array &$state):IdentityChange{$state['mfa']=['secret'=>'encrypted-fixture'];return new IdentityChange(null,true);});rejects(fn()=>signin($auth,$user['email']),'MFA_UNAVAILABLE');
    }
    $delete=$store->createUser('delete@example.test','Delete',$hash);$subjectHash=hash('sha256','deleted-provider-fixture');$store->changeProvider($delete['id'],0,'fixture',$subjectHash);$deleteContext=signin($auth,$delete['email']);$deleted=$manager->update(request(['confirmation'=>'DELETE','current_password'=>'example-password-123']),$deleteContext,'delete');check($deleted['user']===null&&$store->userById($delete['id'])===null&&$store->state('oid:'.$subjectHash)===[]&&$store->session($deleteContext->digest,time())===null&&$store->session($bobContext->digest,time())!==null);
}
if(defined('COREVIA_IDENTITY_CONTRACT_ONLY')){return;}
$directory=sys_get_temp_dir().'/corevia-identity-'.bin2hex(random_bytes(6));mkdir($directory);$crypto=function_exists('openssl_encrypt');
try{
    foreach([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$expected){check(Totp::code(Totp::encode('12345678901234567890'),$time,8)===$expected,'RFC 6238 vector failed');}
    $store=new JsonAuthStore($directory.'/identity.json');contract($store,$crypto);if($crypto){concurrent($store,$directory.'/identity.json',false);}echo "PASS JSON account/session/tenant contracts and MFA fail-closed".($crypto?', TOTP, OAuth PKCE/replay/MFA and concurrent recovery/provider binding':' (OpenSSL features unavailable)')."\n";
    if(class_exists(\PDO::class)&&in_array('sqlite',\PDO::getAvailableDrivers(),true)){$db=SqlConnection::connect(['driver'=>'sqlite','database'=>$directory.'/identity.sqlite']);$store=new SqlAuthStore($db);$store->migrate();$store->migrate();contract($store,$crypto);if($crypto){concurrent($store,$directory.'/identity.sqlite',true);}echo "PASS SQLite identity/security contracts, concurrency and idempotent migration\n";}
    if(getenv('COREVIA_DB_TESTS')==='1'){foreach(\CoreviaTests\Services::drivers() as $driver){$db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_identity_'.bin2hex(random_bytes(5));try{$store=new SqlAuthStore($db,$prefix);$store->migrate();contract($store,$crypto);echo "PASS $driver identity/security contracts\n";}finally{foreach(['state','tokens','sessions','limits','users','mutex'] as $name){$db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$name));}}}}
    if($crypto){$box=SecretBox::fromFile($directory.'/secret.key');$encrypted=$box->seal('private fixture','purpose:user-a');check($box->open($encrypted,'purpose:user-a')==='private fixture');rejects(fn()=>$box->open($encrypted,'purpose:user-b'));rejects(fn()=>(new SecretBox(random_bytes(32)))->open($encrypted,'purpose:user-a'));$bytes=base64_decode(substr($encrypted,5));$bytes[28]=chr(ord($bytes[28])^1);rejects(fn()=>$box->open('gcm1.'.base64_encode($bytes),'purpose:user-a'));check(SecretBox::fromFile($directory.'/secret.key')->open($encrypted,'purpose:user-a')==='private fixture');echo "PASS authenticated encryption tamper, key and purpose binding\n";}
}finally{unset($store,$db);foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
