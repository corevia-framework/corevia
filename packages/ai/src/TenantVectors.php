<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\TenantScope;
/** Bound adapter intentionally omits a caller-selected tenant argument. */
final readonly class TenantVectors
{
    public function __construct(private VectorIndex $index,private TenantScope $scope){}
    public function put(string $id,string $text,array $vector):void{$this->index->put($this->scope->namespace('vectors'),$id,$text,$vector);}
    public function search(array $vector,int $limit=5):array{return $this->index->search($this->scope->namespace('vectors'),$vector,$limit);}
    public function delete(string $id):void{$this->index->delete($this->scope->namespace('vectors'),$id);}
}
