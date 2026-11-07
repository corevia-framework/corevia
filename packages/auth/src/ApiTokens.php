<?php
declare(strict_types=1);
namespace Corevia\Auth;

/** Scoped, purpose-separated bearer credentials tied to current account/session authority. */
final readonly class ApiTokens
{
    private \Closure $clock;
    public function __construct(private IdentityStore $store,private Gate $gate,?callable $clock=null){$this->clock=\Closure::fromCallable($clock??time(...));}
    private function owner(AuthContext $context,int $now):array
    {
        if($context->user===null||$this->store->authority($context->user['id'],$context->digest,$now)===null){throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);}
        return $this->store->userById($context->user['id'])??throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);
    }
    public function issue(AuthContext $context,array $scopes,?string $tenant=null,int $seconds=3600):array
    {
        $now=($this->clock)();$user=$this->owner($context,$now);
        if($this->store->state('api-token:'.$context->digest)!==[]){throw new AuthFailure('FORBIDDEN','API credentials cannot mint other API credentials.',403);}
        if(!array_is_list($scopes)||count($scopes)<1||count($scopes)>32||$seconds<60||$seconds>2592000
            ||$tenant!==null&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D',$tenant)){throw new \InvalidArgumentException('Invalid token scope, tenant or lifetime.');}
        foreach($scopes as $scope){
            if(!is_string($scope)||!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$scope)){throw new \InvalidArgumentException('Use explicit token scopes without wildcards.');}
            if(!$this->gate->allows($context,$scope,new ResourceIdentity($tenant))){throw new AuthFailure('FORBIDDEN','Scope is not granted.',403);}
        }
        if(!$this->store->consume([['api-token-issue:'.hash('sha256',$user['id']),10,60]],$now)){throw new AuthFailure('RATE_LIMIT','Try again later.',429);}
        $scopes=array_values(array_unique($scopes));sort($scopes,SORT_STRING);
        $token='cv4_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$id=hash('sha256',$token);$expires=$now+$seconds;
        $issuer=$this->store->session($context->digest,$now)??throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);
        $this->store->changeState('api-token:'.$id,static function(array &$state)use($user,$scopes,$tenant,$expires):void{$state=['user_id'=>$user['id'],'scopes'=>$scopes,'tenant'=>$tenant,'expires'=>$expires,'window'=>0,'used'=>0];});
        // The current authority check proves this session passed the store's MFA/version rules.
        // SQL stores deliberately do not persist a separate mfa_verified column on read.
        try{$this->store->saveSession($id,['user_id'=>$user['id'],'user_version'=>$user['auth_version']??0,'csrf'=>bin2hex(random_bytes(32)),'expires'=>$expires,'mfa_verified'=>true],$now);}
        catch(\Throwable $error){$this->store->changeState('api-token:'.$id,static function(array &$state):void{$state=[];});throw $error;}
        return ['id'=>$id,'token'=>$token,'scopes'=>$scopes,'tenant'=>$tenant,'expires'=>$expires];
    }
    public function authenticate(string $token,string $scope,?string $tenant=null,int $perMinute=120):AuthContext
    {
        if(!preg_match('/^cv4_[A-Za-z0-9_-]{43}$/D',$token)){throw new AuthFailure('UNAUTHENTICATED','Invalid API credential.',401);}
        if($perMinute<1||$perMinute>10000){throw new \InvalidArgumentException('Invalid API quota.');}
        $now=($this->clock)();$id=hash('sha256',$token);$key='api-token:'.$id;$record=$this->store->state($key);
        if(($record['expires']??0)<=$now||!is_string($record['user_id']??null)){throw new AuthFailure('UNAUTHENTICATED','Invalid API credential.',401);}
        if(($record['tenant']??null)!==$tenant||!in_array($scope,$record['scopes']??[],true)){throw new AuthFailure('FORBIDDEN','Scope or tenant is not granted.',403);}
        $user=$this->store->userById($record['user_id'])??throw new AuthFailure('UNAUTHENTICATED','Invalid API credential.',401);
        $context=new AuthContext($id,'',['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email']],null);
        if(!$this->gate->allows($context,$scope,new ResourceIdentity($tenant))){throw new AuthFailure('UNAUTHENTICATED','API authority changed.',401);}
        $this->store->changeState($key,static function(array &$state)use($now,$perMinute):void{
            if(($state['expires']??0)<=$now){throw new AuthFailure('UNAUTHENTICATED','Invalid API credential.',401);}
            $window=intdiv($now,60);if(($state['window']??0)!==$window){$state['window']=$window;$state['used']=0;}
            if($state['used']>=$perMinute){throw new AuthFailure('RATE_LIMIT','API quota exceeded.',429);}$state['used']++;
        });return $context;
    }
    public function revoke(AuthContext $owner,string $id):void
    {
        $user=$this->owner($owner,($this->clock)());
        if(!preg_match('/^[a-f0-9]{64}$/D',$id)){throw new \InvalidArgumentException('Invalid token ID.');}
        $this->store->changeState('api-token:'.$id,static function(array &$state)use($user):void{
            if($state!==[]&&($state['user_id']??null)!==$user['id']){throw new AuthFailure('FORBIDDEN','Credential is not owned.',403);}$state=[];
        });$this->store->deleteSession($id);
    }
}
