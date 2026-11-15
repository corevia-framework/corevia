<?php
declare(strict_types=1);
namespace Corevia\Core;
interface ToolApprovals
{
    public function propose(TenantScope $scope,string $fingerprint,bool $humanApproval,int $now,int $seconds=300):string;
    public function approve(TenantScope $approver,string $requestId,string $expectedFingerprint,int $now):void;
    public function consume(TenantScope $scope,string $requestId,string $fingerprint,int $now):bool;
}
