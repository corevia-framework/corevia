<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** Uses the official optional ext-mongodb driver; no third-party runtime dependency in core. */
final class MongoDocumentStore implements DocumentStore
{
    private readonly \MongoDB\Driver\Manager $manager;
    public function __construct(string $uri,private readonly string $database,array $options=[])
    {
        if(!extension_loaded('mongodb')){throw new \RuntimeException('Enable the optional mongodb PHP extension.');}
        DocumentRules::collection($database);
        if(!preg_match('#^mongodb(?:\+srv)?://#',$uri)){throw new \InvalidArgumentException('A MongoDB URI is required.');}
        $this->manager=new \MongoDB\Driver\Manager($uri,['serverSelectionTimeoutMS'=>5000,'connectTimeoutMS'=>5000,...$options]);
    }
    private function namespace(string $collection): string { DocumentRules::collection($collection);return $this->database.'.'.$collection; }
    public function get(string $collection,string $id): ?array { DocumentRules::id($id);return $this->find($collection,['_id'=>$id],1)[0] ?? null; }
    public function put(string $collection,string $id,array $document): void
    {
        DocumentRules::id($id);DocumentRules::document($document);$document['_id']=$id;$bulk=new \MongoDB\Driver\BulkWrite();$bulk->update(['_id'=>$id],$document,['upsert'=>true,'multi'=>false]);$this->manager->executeBulkWrite($this->namespace($collection),$bulk);
    }
    public function delete(string $collection,string $id): bool
    {
        DocumentRules::id($id);$bulk=new \MongoDB\Driver\BulkWrite();$bulk->delete(['_id'=>$id],['limit'=>1]);return $this->manager->executeBulkWrite($this->namespace($collection),$bulk)->getDeletedCount()>0;
    }
    public function find(string $collection,array $equals=[],int $limit=100): array
    {
        DocumentRules::filter($equals,$limit);$filter=$equals;foreach($filter as $key=>$value){if($value===null){$filter[$key]=['$eq'=>null,'$exists'=>true];}}
        $query=new \MongoDB\Driver\Query($filter,['limit'=>$limit,'maxTimeMS'=>5000]);$cursor=$this->manager->executeQuery($this->namespace($collection),$query);$cursor->setTypeMap(['root'=>'array','document'=>'array','array'=>'array']);return $cursor->toArray();
    }
}
