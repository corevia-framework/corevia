<?php
declare(strict_types=1);
namespace Corevia\Auth;

/** Server-configured federation policy. Email domains never grant organization membership. */
final readonly class OrganizationSso implements OAuthLoginPolicy
{
    private \Closure $clock;
    public function __construct(private IdentityStore $store,private array $policies,?callable $clock=null)
    {
        if($policies===[]||count($policies)>32){throw new \InvalidArgumentException('Declare organization SSO policies.');}
        foreach($policies as $provider=>$policy){
            if(!is_string($provider)||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$provider)||!is_array($policy)||array_diff(array_keys($policy),['tenant','roles','max_age'])
                ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D',$policy['tenant']??'')||!is_array($policy['roles']??null)||!array_is_list($policy['roles'])||$policy['roles']===[]||count($policy['roles'])>32
                ||!is_int($policy['max_age']??3600)||($policy['max_age']??3600)<60||($policy['max_age']??3600)>86400){throw new \InvalidArgumentException('Invalid organization SSO policy.');}
            foreach($policy['roles'] as $role){if(!is_string($role)||!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$role)){throw new \InvalidArgumentException('Use explicit provisioned SSO roles.');}}
        }$this->clock=\Closure::fromCallable($clock??time(...));
    }
    public function validate(string $provider,array $user,bool $linking):void
    {
        if(!isset($this->policies[$provider])){return;}$policy=$this->policies[$provider];$current=$this->store->userById($user['id']);$security=$this->store->state('user:'.$user['id']);
        if($current===null||($current['auth_version']??0)!==($user['auth_version']??0)||isset($security['lifecycle_deletion'])||array_intersect($policy['roles'],$security['tenants'][$policy['tenant']]??[])===[]){throw new AuthFailure('SSO_MEMBERSHIP','Organization membership is required.',403);}
    }
    public function validateProviders(array $providers):void
    {
        foreach($this->policies as $name=>$policy){if(!($providers[$name]??null) instanceof OidcProvider){throw new \InvalidArgumentException('Organization SSO requires a configured nonce-verifying OIDC provider.');}}
    }
    public function authenticated(string $provider,AuthContext $context):void
    {
        if(!isset($this->policies[$provider])){return;}$id=$context->user['id']??null;$user=is_string($id)?$this->store->userById($id):null;
        if($user===null||$this->store->authority($id,$context->digest,($this->clock)())===null){throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);}$this->validate($provider,$user,false);
        $session=$this->store->session($context->digest,($this->clock)())??throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);$policy=$this->policies[$provider];
        $record=['user_id'=>$id,'provider'=>$provider,'tenant'=>$policy['tenant'],'version'=>$user['auth_version']??0,'issued'=>($this->clock)(),'expires'=>min($session['expires'],($this->clock)()+($policy['max_age']??3600))];
        $this->store->changeState('sso-session:'.$context->digest,static function(array &$state)use($record):void{$state=$record;});
    }
    public function requireSession(AuthContext $context,string $tenant):void
    {
        $id=$context->user['id']??null;$record=$this->store->state('sso-session:'.$context->digest);$provider=$record['provider']??null;$now=($this->clock)();
        if(!is_string($id)||!is_string($provider)||!isset($this->policies[$provider])||($record['user_id']??null)!==$id||($record['tenant']??null)!==$tenant||$this->policies[$provider]['tenant']!==$tenant
            ||($record['expires']??0)<=$now||!is_int($record['issued']??null)||$record['issued']>$now||$now-$record['issued']>=($this->policies[$provider]['max_age']??3600)
            ||$this->store->authority($id,$context->digest,$now)===null){throw new AuthFailure('SSO_REQUIRED','Sign in with your organization provider.',403);}
        $user=$this->store->userById($id)??throw new AuthFailure('SSO_REQUIRED','Sign in with your organization provider.',403);if(($record['version']??null)!==($user['auth_version']??0)){throw new AuthFailure('SSO_REQUIRED','Sign in with your organization provider.',403);}$this->validate($provider,$user,false);
    }
}
