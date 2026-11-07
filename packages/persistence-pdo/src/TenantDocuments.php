<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\TenantScope;
final readonly class TenantDocuments implements DocumentStore
{
    public function __construct(private DocumentStore $store,private TenantScope $scope){}
    private function collection(string $collection):string{DocumentRules::collection($collection);return 't'.substr($this->scope->namespace('documents:'.$collection),0,63);}
    public function get(string $collection,string $id):?array{return $this->store->get($this->collection($collection),$id);}
    public function put(string $collection,string $id,array $document):void
    {
        if(array_key_exists('tenant_id',$document)){throw new \InvalidArgumentException('Tenant ownership is not a document field.');}
        $this->store->put($this->collection($collection),$id,$document);
    }
    public function delete(string $collection,string $id):bool{return $this->store->delete($this->collection($collection),$id);}
    public function find(string $collection,array $equals=[],int $limit=100):array{return $this->store->find($this->collection($collection),$equals,$limit);}
}
