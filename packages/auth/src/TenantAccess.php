<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\TenantScope;
final readonly class TenantAccess
{
    public function __construct(private Gate $gate){}
    public function resolve(AuthContext $identity,string $tenant,string $ability):TenantScope
    {
        if($identity->user===null){throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);}
        return new TenantScope($tenant,$identity->user['id'],fn():bool=>$this->gate->allows($identity,$ability,new ResourceIdentity($tenant)));
    }
}
