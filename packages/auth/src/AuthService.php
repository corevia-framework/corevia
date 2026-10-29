<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\Request;

final readonly class AuthService
{
    // Public dummy credential, never a real account. Matches the work factor of stored hashes.
    private const DUMMY='$2y$12$Ol2IbOEv.1Jwug0pfkv0j.fwLP8dP5uA88jUf5sQNNFKN9BwqAvCW';
    private \Closure $clock;
    public string $origin;
    public string $cookieName;
    private bool $secure;
    public function __construct(private AuthStore $store,string $url='http://localhost:8081',public bool $registration=true,private int $ttl=28800,?callable $clock=null,private ?MfaService $mfa=null,private int $rememberTtl=2592000)
    {
        $this->origin=self::origin($url)??throw new \InvalidArgumentException('APP_URL must be an HTTP(S) origin without credentials, query or fragment.');
        $parts=parse_url($url);if(!in_array($parts['path']??'',['','/'],true)){throw new \InvalidArgumentException('APP_URL must not contain a path.');}
        $this->secure=str_starts_with($this->origin,'https://');$this->cookieName=$this->secure?'__Host-corevia_auth':'corevia_auth';
        if(getenv('APP_ENV')==='production' && !$this->secure){throw new \LogicException('Production auth requires an HTTPS APP_URL.');}
        if($ttl<60 || $ttl>86400){throw new \InvalidArgumentException('Session lifetime must be 60..86400 seconds.');}$this->clock=\Closure::fromCallable($clock??'time');
        if($rememberTtl<60 || $rememberTtl>2592000){throw new \InvalidArgumentException('Remembered sessions must expire within 30 days.');}
    }
    private static function origin(string $url):?string
    {
        $parts=parse_url($url);if($parts===false || !in_array(strtolower($parts['scheme']??''),['http','https'],true) || empty($parts['host']) || isset($parts['user'],$parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])){return null;}
        $scheme=strtolower($parts['scheme']);$port=$parts['port']??($scheme==='https'?443:80);
        return $scheme.'://'.strtolower($parts['host']).(($scheme==='https' && $port===443)||($scheme==='http' && $port===80)?'':':'.$port);
    }
    private static function user(array $user):array{return ['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email']];}
    public function cookie(string $value,int $age):string{return $this->cookieName.'='.$value.'; Path=/; Max-Age='.$age.'; HttpOnly; SameSite=Lax'.($this->secure?'; Secure':'');}
    private function create(?array $user,bool $mfaVerified=false,bool $remember=false):AuthContext
    {
        $token=bin2hex(random_bytes(32));$digest=hash('sha256',$token);$csrf=bin2hex(random_bytes(32));
        $ttl=$remember?$this->rememberTtl:$this->ttl;
        $this->store->saveSession($digest,['user_id'=>$user['id']??null,'user_version'=>$user['auth_version']??0,'csrf'=>$csrf,'expires'=>($this->clock)()+$ttl,'mfa_verified'=>$mfaVerified],($this->clock)());
        return new AuthContext($digest,$csrf,$user===null?null:self::user($user),$this->cookie($token,$ttl));
    }
    public function open(Request $request):?AuthContext
    {
        $token=$request->cookie($this->cookieName);$record=null;$digest='';
        if(is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)){$digest=hash('sha256',$token);$record=$this->store->session($digest,($this->clock)());}
        if($record!==null){
            if(!is_string($record['csrf']??null) || !preg_match('/^[a-f0-9]{64}$/D',$record['csrf']) || !array_key_exists('user_id',$record)){throw new \RuntimeException('Invalid session record.');}
            $user=$record['user_id']===null?null:$this->store->userById($record['user_id']);
            if($record['user_id']===null || ($user!==null && ($record['user_version']??0)===($user['auth_version']??0))){return new AuthContext($digest,$record['csrf'],$user===null?null:self::user($user),null);}
        }
        if(!in_array($request->method,['GET','HEAD'],true)){return null;}
        if(!$this->store->consume([[hash('sha256','session|'.$this->ip($request)),120,900]],($this->clock)())){throw new AuthFailure('RATE_LIMITED','Too many requests. Try again later.',429);}
        return $this->create(null);
    }
    public function verify(Request $request,AuthContext $context):void
    {
        $origin=$request->header('origin');$referer=$request->header('referer');
        if(($origin!=='' && self::origin($origin)!==$this->origin) || ($origin==='' && $referer!=='' && self::origin($referer)!==$this->origin)){throw new AuthFailure('CSRF','Reload the page and try again.',419);}
        $input=$request->input();$csrf=$request->header('x-csrf-token')?:($input['_token']??null);
        if(!is_string($csrf) || !hash_equals($context->csrf,$csrf)){throw new AuthFailure('CSRF','Reload the page and try again.',419);}
    }
    private function limits(Request $request,string $operation,string $email):void
    {
        $ip=$this->ip($request);
        if(!$this->store->consume([[hash('sha256',$operation.'|ip|'.$ip),30,900],[hash('sha256',$operation.'|email|'.$email),10,900]],($this->clock)())){throw new AuthFailure('RATE_LIMITED','Too many attempts. Try again later.',429);}
    }
    private function ip(Request $request):string{$ip=$request->attributes['client_ip']??'local';return is_string($ip) && filter_var($ip,FILTER_VALIDATE_IP)!==false?$ip:'local';}
    public function login(Request $request,AuthContext $context):AuthContext
    {
        $input=$request->input();$email=is_string($input['email']??null)?strtolower(trim($input['email'])):'';$password=$input['password']??null;
        $this->limits($request,'login',substr($email,0,254));
        if(!is_string($password) || strlen($password)>72 || str_contains($password,"\0") || strlen($email)>254){throw new AuthFailure('CREDENTIALS','Email or password is incorrect.',401);}
        $user=$this->store->findUser($email);$valid=password_verify($password,$user['password_hash']??self::DUMMY);
        if(!$valid || $user===null){throw new AuthFailure('CREDENTIALS','Email or password is incorrect.',401);}
        return $this->authenticate($context,$user,$input['code']??null,in_array($input['remember']??false,[true,'1','on'],true));
    }
    public function register(Request $request,AuthContext $context):AuthContext
    {
        if(!$this->registration){throw new AuthFailure('REGISTRATION_DISABLED','Registration is disabled.',403);}
        $input=$request->input();$email=is_string($input['email']??null)?strtolower(trim($input['email'])):'';$name=$input['name']??null;$password=$input['password']??null;
        $this->limits($request,'register',substr($email,0,254));
        if(!is_string($name) || trim($name)==='' || preg_match('//u',$name)!==1 || preg_match('/[\x00-\x1f\x7f]/',$name) || preg_match_all('/./us',$name)>80 || strlen($email)>254 || filter_var($email,FILTER_VALIDATE_EMAIL)===false){throw new AuthFailure('VALIDATION','Use a valid name and email address.');}
        $hash=self::passwordHash($password,$input['password_confirmation']??null);$user=$this->store->createUser($email,trim($name),$hash);
        if($user===null){throw new AuthFailure('REGISTRATION','Unable to create this account. Try signing in.');}return $this->rotate($context,$user);
    }
    private function rotate(AuthContext $context,array $user):AuthContext{$fresh=$this->create($user);$this->store->deleteSession($context->digest);return $fresh;}
    /** Trusted server-side identity, never accept $user from request input. MFA is always checked. */
    public function authenticate(AuthContext $context,array $user,mixed $code=null,bool $remember=false):AuthContext
    {
        if($this->mfa===null && $this->store instanceof IdentityStore && isset($this->store->state('user:'.$user['id'])['mfa']['secret'])){throw new AuthFailure('MFA_UNAVAILABLE','Authenticator verification is unavailable.',503);}
        $this->mfa?->verify($user,$code);return $this->replaceSession($context,$user,true,$remember);
    }
    /** Internal completion after a credential-checked identity transaction. */
    public function replaceSession(AuthContext $context,array $user,bool $mfaVerified=false,bool $remember=false):AuthContext{$fresh=$this->create($user,$mfaVerified,$remember);$this->store->deleteSession($context->digest);return $fresh;}
    public function confirm(Request $request,AuthContext $context):array
    {
        $record=$this->store->session($context->digest,($this->clock)());$user=$context->user===null?null:$this->store->userById($context->user['id']);
        if($user===null || ($record['user_id']??null)!==$user['id'] || ($record['user_version']??0)!==($user['auth_version']??0)){throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);}
        $this->limits($request,'confirm',$user['id']);$input=$request->input();$password=$input['current_password']??null;
        if(!is_string($password)||strlen($password)>72||str_contains($password,"\0")||!password_verify($password,$user['password_hash'])){throw new AuthFailure('CREDENTIALS','Current password is incorrect.',401);}
        if($this->mfa===null && $this->store instanceof IdentityStore && isset($this->store->state('user:'.$user['id'])['mfa']['secret'])){throw new AuthFailure('MFA_UNAVAILABLE','Authenticator verification is unavailable.',503);}$this->mfa?->verify($user,$input['code']??null);return $user;
    }
    public static function passwordHash(mixed $password,mixed $confirmation):string
    {
        if(!is_string($password) || preg_match('//u',$password)!==1 || preg_match_all('/./us',$password)<12 || strlen($password)>72 || str_contains($password,"\0") || $confirmation!==$password){throw new AuthFailure('VALIDATION','Use matching passwords of at least 12 characters (at most 72 bytes).');}
        return password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
    }
    public function logout(AuthContext $context):string{$this->store->deleteSession($context->digest);return $this->cookie('',0);}
}
