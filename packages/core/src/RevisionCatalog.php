<?php
declare(strict_types=1);
namespace Corevia\Core;
interface RevisionCatalog
{
    public function begin(TenantScope $scope,string $document,int $expectedVersion,string $model,string $contentHash,int $chunks):array;
    public function activate(TenantScope $scope,string $revision):array;
    public function abandon(TenantScope $scope,string $revision):bool;
    public function active(TenantScope $scope):array;
    public function delete(TenantScope $scope,string $document,int $expectedVersion):array;
    public function collectable(TenantScope $scope,int $limit=20):array;
    public function collected(TenantScope $scope,string $revision):void;
}
