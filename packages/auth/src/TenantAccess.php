<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\TenantScope;
final readonly class TenantAccess
{
    public function __construct(private Gate $gate){}
    public function resolve(AuthContext $identity,string $tenant,string $ability):TenantScope
    {
        return $this->resolveMany($identity,$tenant,[$ability]);
    }
    public function resolveMany(AuthContext $identity,string $tenant,array $abilities):TenantScope
    {
        if($identity->user===null){throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);}
        if(!array_is_list($abilities)||$abilities===[]||count($abilities)>64){throw new \InvalidArgumentException('Use explicit tenant capabilities.');}
        $authority=function()use($identity,$tenant,$abilities):bool{foreach($abilities as $ability){if(!is_string($ability)||!$this->gate->allows($identity,$ability,new ResourceIdentity($tenant))){return false;}}return true;};
        return new TenantScope($tenant,$identity->user['id'],$authority,$abilities);
    }
}
