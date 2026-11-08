<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\TenantScope;

/** Organization-owned machine credentials, purpose-separated from browser/user API sessions. */
final readonly class ServiceAccounts
{
    private \Closure $clock;
    public function __construct(private IdentityStore $store,private array $allowedScopes,?callable $clock=null)
    {
        if(!array_is_list($allowedScopes)||$allowedScopes===[]||count($allowedScopes)>64){throw new \InvalidArgumentException('Declare machine capabilities explicitly.');}
        foreach($allowedScopes as $scope){if(!is_string($scope)||!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$scope)||in_array($scope,['service.manage','service.read'],true)){throw new \InvalidArgumentException('Machine credentials cannot manage credentials.');}}
        $this->clock=\Closure::fromCallable($clock??time(...));
    }
    private function now():int{return ($this->clock)();}
    private function key(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new \InvalidArgumentException('Invalid service account ID.');}return 'service-account:'.$id;}
    private function credential(string $id):string{return 'cvs4_'.$id.'.'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function descriptor(array $record):array{return array_intersect_key($record,array_flip(['id','name','tenant','scopes','version','status','expires']));}
    public function create(TenantScope $operator,string $name,array $scopes,int $seconds=86400):array
    {
        $operator->requireAbility('service.manage');
        if(trim($name)===''||strlen($name)>120||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name)||!array_is_list($scopes)||$scopes===[]||count($scopes)>64||array_diff($scopes,$this->allowedScopes)||$seconds<60||$seconds>7776000){throw new \InvalidArgumentException('Invalid service name, capabilities or expiry.');}
        $scopes=array_values(array_unique($scopes));sort($scopes,SORT_STRING);$id=bin2hex(random_bytes(16));$token=$this->credential($id);
        $record=['id'=>$id,'name'=>trim($name),'tenant'=>$operator->tenant,'scopes'=>$scopes,'version'=>1,'status'=>'active','digest'=>hash('sha256',$token),'expires'=>$this->now()+$seconds,'window'=>0,'used'=>0];
        $this->store->changeState($this->key($id),static function(array &$state)use($record):void{if($state!==[]){throw new \RuntimeException('Service ID collision.');}$state=$record;});
        return $this->descriptor($record)+['credential'=>$token];
    }
    private function owned(TenantScope $operator,string $id):array
    {
        $state=$this->store->state($this->key($id));if($state===[]||($state['tenant']??null)!==$operator->tenant){throw new AuthFailure('NOT_FOUND','Service account not found.',404);}return $state;
    }
    public function describe(TenantScope $operator,string $id):array{$operator->requireAbility('service.read');return $this->descriptor($this->owned($operator,$id));}
    public function rotate(TenantScope $operator,string $id):array
    {
        $operator->requireAbility('service.manage');$token=$this->credential($id);$now=$this->now();
        $record=$this->store->changeState($this->key($id),static function(array &$state)use($operator,$token,$now):array{
            if(($state['tenant']??null)!==$operator->tenant){throw new AuthFailure('NOT_FOUND','Service account not found.',404);}
            if(($state['status']??null)!=='active'||($state['expires']??0)<=$now){throw new AuthFailure('SERVICE_INACTIVE','Provision a new service account.',409);}
            $state['digest']=hash('sha256',$token);$state['version']++;$state['used']=0;return $state;
        });return $this->descriptor($record)+['credential'=>$token];
    }
    public function revoke(TenantScope $operator,string $id):void
    {
        $operator->requireAbility('service.manage');$this->store->changeState($this->key($id),static function(array &$state)use($operator):void{
            if(($state['tenant']??null)!==$operator->tenant){throw new AuthFailure('NOT_FOUND','Service account not found.',404);}
            $state['status']='revoked';$state['digest']='';$state['version']++;
        });
    }
    public function authenticate(string $token,string $tenant,string $ability,int $perMinute=120):TenantScope
    {
        if(!preg_match('/^cvs4_([a-f0-9]{32})\.[A-Za-z0-9_-]{43}$/D',$token,$match)){throw new AuthFailure('UNAUTHENTICATED','Invalid machine credential.',401);}
        if($perMinute<1||$perMinute>10000){throw new \InvalidArgumentException('Invalid service quota.');}
        $id=$match[1];$key=$this->key($id);$digest=hash('sha256',$token);$now=$this->now();
        $record=$this->store->changeState($key,static function(array &$state)use($digest,$tenant,$ability,$now,$perMinute):array{
            if(($state['status']??null)!=='active'||($state['expires']??0)<=$now||!hash_equals($state['digest']??'',$digest)){throw new AuthFailure('UNAUTHENTICATED','Invalid machine credential.',401);}
            if(($state['tenant']??null)!==$tenant||!in_array($ability,$state['scopes']??[],true)){throw new AuthFailure('FORBIDDEN','Machine capability is not granted.',403);}
            $window=intdiv($now,60);if(($state['window']??0)!==$window){$state['window']=$window;$state['used']=0;}
            if($state['used']>=$perMinute){throw new AuthFailure('RATE_LIMIT','Machine quota exceeded.',429);}$state['used']++;return $state;
        });
        return new TenantScope($tenant,'service-'.$id,function()use($key,$record,$digest,$ability):bool{
            $current=$this->store->state($key);return ($current['status']??null)==='active'&&($current['expires']??0)>$this->now()&&($current['version']??null)===$record['version']
                &&($current['tenant']??null)===$record['tenant']&&hash_equals($current['digest']??'',$digest)&&in_array($ability,$current['scopes']??[],true);
        },[$ability]);
    }
}
