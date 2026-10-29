<?php
declare(strict_types=1);
namespace Corevia\Auth;
final readonly class Gate
{
    public function __construct(private IdentityStore $store,private array $roles){}
    public function allows(AuthContext $context,string $ability,?ResourceIdentity $resource=null,bool $owned=false):bool
    {
        if($context->user===null){return false;}$state=$this->store->authority($context->user['id'],$context->digest,time());if($state===null){return false;}
        if($owned && ($resource===null || $resource->owner!==$context->user['id'])){return false;}
        $assigned=$resource?->tenant!==null?($state['tenants'][$resource->tenant]??[]):($state['roles']??[]);
        foreach($assigned as $role){if(in_array($ability,$this->roles[$role]??[],true)){return true;}}return false;
    }
    /** Operator/policy API only; never expose role grants through a public account form. */
    public function assign(string $userId,array $roles,?string $tenant=null):void
    {
        foreach($roles as $role){if(!is_string($role)||!array_key_exists($role,$this->roles)){throw new \InvalidArgumentException('Unknown role.');}}
        if($tenant!==null && (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D',$tenant))){throw new \InvalidArgumentException('Invalid tenant identifier.');}
        $user=$this->store->userById($userId)??throw new \InvalidArgumentException('Account not found.');$this->store->changeIdentity($userId,$user['auth_version']??0,static function(array &$row,array &$state)use($roles,$tenant):IdentityChange{if($tenant===null){$state['roles']=array_values(array_unique($roles));}else{$state['tenants'][$tenant]=array_values(array_unique($roles));}return new IdentityChange(null,true);});
    }
}
