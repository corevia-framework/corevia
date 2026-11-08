<?php
declare(strict_types=1);
namespace CoreviaV4Sso;
require dirname(__DIR__,2).'/autoload.php';require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{AuthContext,AuthFailure,AuthService,Gate,IdentityStore,JsonAuthStore,MfaService,OAuthService,OidcProvider,OrganizationSso,RequireOrganizationSso,SecretBox,SqlAuthStore,Totp};
use Corevia\Http\{ApiResponse,Request};
use Corevia\Persistence\SqlConnection;
use Corevia\Testing\Clock;
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Organization SSO contract failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null&&(!$error instanceof AuthFailure||$error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('SSO policy bypass accepted.');}
function request(array $body=[]):Request{return new Request('POST','/fixture',['content-type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR));}
final class FixtureOidc implements OidcProvider
{
    public string $subject='member-subject';
    public function authorizationEndpoint():string{return 'https://idp.example.test/authorize';}
    public function clientId():string{return 'fixture-only-client';}
    public function scopes():array{return ['openid'];}
    public function identity(string $code,string $verifier,string $redirectUri):string{throw new \LogicException('OIDC must verify a nonce.');}
    public function identityWithNonce(string $code,string $verifier,string $redirectUri,string $nonce):string{check(strlen($nonce)===64&&strlen($verifier)===43&&$redirectUri==='https://app.example.test/auth/oauth/company/callback');return $this->subject;}
}
function exchange(OAuthService $oauth,AuthContext $context,string $password):?AuthContext
{
    $url=$oauth->start(request(['current_password'=>$password]),$context,'company');parse_str(parse_url($url,PHP_URL_QUERY),$query);
    return $oauth->callback(new Request('GET','/auth/oauth/company/callback',attributes:['query'=>['state'=>$query['state'],'code'=>'fixture-code']]),$context,'company');
}
function contract(IdentityStore $store,string $label):void
{
    $password='fixture-password-123';$clock=new Clock(time());$box=new SecretBox(random_bytes(32));$mfa=new MfaService($store,$box,$clock);$auth=new AuthService($store,'https://app.example.test',clock:$clock,mfa:$mfa);
    $register=fn($email)=>$auth->register(request(['name'=>'Fixture','email'=>$email,'password'=>$password,'password_confirmation'=>$password]),$auth->open(new Request('GET','/login')));
    $member=$register('member@company.example');$guest=$register('guest@company.example');$gate=new Gate($store,['member'=>['records.read']]);$gate->assign($member->user['id'],['member'],'alpha');
    $member=$auth->login(request(['email'=>'member@company.example','password'=>$password]),$auth->open(new Request('GET','/login')));
    $policy=new OrganizationSso($store,['company'=>['tenant'=>'alpha','roles'=>['member'],'max_age'=>60]],$clock);$provider=new FixtureOidc();$oauth=new OAuthService($store,$box,$auth,['company'=>$provider],$policy);
    rejects(fn()=>new OAuthService($store,$box,$auth,[],$policy));rejects(fn()=>$policy->requireSession($member,'alpha'),'SSO_REQUIRED');
    // A matching email domain never grants membership or permits provider linking.
    $provider->subject='guest-subject';rejects(fn()=>exchange($oauth,$guest,$password),'SSO_MEMBERSHIP');check(($store->state('user:'.$guest->user['id'])['oauth']??[])===[]);
    $provider->subject='member-subject';$linked=exchange($oauth,$member,$password);$policy->requireSession($linked,'alpha');rejects(fn()=>$policy->requireSession($linked,'beta'),'SSO_REQUIRED');
    $passwordOnly=$auth->login(request(['email'=>'member@company.example','password'=>$password]),$auth->open(new Request('GET','/login')));rejects(fn()=>$policy->requireSession($passwordOnly,'alpha'),'SSO_REQUIRED');
    $signed=exchange($oauth,$auth->open(new Request('GET','/login')),$password);$policy->requireSession($signed,'alpha');
    $middleware=new RequireOrganizationSso($policy,'alpha');check($middleware->process(new Request('GET','/private',attributes:['auth'=>$signed]),fn()=>ApiResponse::data([]))->status===200);
    check($middleware->process(new Request('GET','/private',attributes:['auth'=>$passwordOnly]),fn()=>ApiResponse::data([]))->status===403);
    $clock->advance(61);rejects(fn()=>$policy->requireSession($signed,'alpha'),'SSO_REQUIRED');
    // MFA completion, not provider callback alone, establishes organization session assurance.
    $user=$store->userById($signed->user['id']);$setup=$mfa->begin($user,$signed,'Corevia fixture');$enabled=$mfa->enable($user,$signed,Totp::code($setup['secret'],$clock()));
    $anon=$auth->open(new Request('GET','/login'));check(exchange($oauth,$anon,$password)===null);rejects(fn()=>$policy->requireSession($anon,'alpha'),'SSO_REQUIRED');
    rejects(fn()=>$oauth->finishMfa(request(),$anon),'MFA_REQUIRED');$strong=$oauth->finishMfa(request(['code'=>$enabled['value'][0]]),$anon);$policy->requireSession($strong,'alpha');
    $gate->assign($strong->user['id'],[],'alpha');rejects(fn()=>$policy->requireSession($strong,'alpha'),'SSO_REQUIRED');
    echo 'PASS '.$label." provisioned organization membership, no domain auto-linking, provider assurance, MFA, tenant/age enforcement and membership revocation\n";
}
if(!function_exists('openssl_encrypt')){echo "SKIP organization exchange fixtures require optional OpenSSL\n";exit;}
$directory=sys_get_temp_dir().'/corevia-v4-sso-'.bin2hex(random_bytes(6));mkdir($directory);
try{contract(new JsonAuthStore($directory.'/auth.json'),'JSON');$sql=new SqlAuthStore(new SqlConnection(new \PDO('sqlite::memory:')));$sql->migrate();contract($sql,'SQLite');}
finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-sso-');}

if(getenv('COREVIA_DB_TESTS')==='1'){
    require_once dirname(__DIR__).'/support/IdentityServices.php';
    \CoreviaTests\IdentityServices::run(static function($store,string $label):void{contract($store,$label);});
}
