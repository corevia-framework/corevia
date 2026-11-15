<?php
declare(strict_types=1);
namespace Corevia\Core;
interface SyncStore
{
    public function mutateOnce(TenantScope $scope,string $operationId,string $id,int $expectedRevision,?array $data):array;
    public function changes(TenantScope $scope,?string $cursor,int $now,int $limit=100):array;
}
