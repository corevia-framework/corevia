<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\ToolApprovals;
use Corevia\Http\Request;

/** Application approval endpoint helper: current human session, password/MFA confirmation and tenant role. */
final readonly class HumanToolApproval
{
    private \Closure $clock;
    public function __construct(private IdentityStore $store,private AuthService $auth,private TenantAccess $tenants,private ToolApprovals $approvals,?callable $clock=null)
    {
        $this->clock=\Closure::fromCallable($clock??time(...));
    }
    public function approve(Request $request,AuthContext $human,string $tenant,string $requestId,string $reviewedFingerprint):void
    {
        if ($this->store->state('api-token:'.$human->digest)!==[]) { throw new AuthFailure('FORBIDDEN','Tool review requires a human session.',403); }
        $this->auth->confirm($request,$human);$scope=$this->tenants->resolve($human,$tenant,'ai.tools.approve');
        $this->approvals->approve($scope,$requestId,$reviewedFingerprint,($this->clock)());
    }
}
