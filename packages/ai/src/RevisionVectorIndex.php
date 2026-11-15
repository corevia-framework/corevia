<?php
declare(strict_types=1);
namespace Corevia\Ai;
interface RevisionVectorIndex
{
    public function putRevision(string $tenant,string $revision,string $id,string $text,array $vector):void;
    public function searchRevisions(string $tenant,array $revisions,array $vector,int $limit=5):array;
    public function deleteRevision(string $tenant,string $revision):void;
}
