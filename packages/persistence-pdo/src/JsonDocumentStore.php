<?php
declare(strict_types=1);
namespace Corevia\Persistence;

final class JsonDocumentStore implements DocumentStore
{
    public function __construct(private readonly string $directory)
    {
        if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)){throw new \RuntimeException('Cannot create private document storage.');}
    }
    private function access(string $collection,callable $work,bool $write=false): mixed
    {
        DocumentRules::collection($collection);$path=$this->directory.'/'.$collection.'.json';$lock=fopen($path.'.lock','c+b');
        if($lock===false || !flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock document storage.');}
        try {
            $data=[];if(is_file($path)){if(filesize($path)>16777216){throw new \LengthException('Collection exceeds 16MiB.');}$data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if(!is_array($data)){throw new \RuntimeException('Invalid document storage.');}}
            $result=$work($data);
            if($write){$wire=json_encode($data,JSON_THROW_ON_ERROR);if(strlen($wire)>16777216){throw new \LengthException('Collection exceeds 16MiB.');}$temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';try{if(file_put_contents($temp,$wire,LOCK_EX)===false || !chmod($temp,0600) || !rename($temp,$path)){throw new \RuntimeException('Cannot save document storage.');}}finally{if(is_file($temp)){unlink($temp);}}}
            return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function get(string $collection,string $id): ?array { DocumentRules::id($id);return $this->access($collection,fn(array $data):?array=>$data[$id] ?? null); }
    public function put(string $collection,string $id,array $document): void
    {
        DocumentRules::id($id);DocumentRules::document($document);$document['_id']=$id;
        $this->access($collection,function(array &$data)use($id,$document):void{$data[$id]=$document;},true);
    }
    public function delete(string $collection,string $id): bool
    {
        DocumentRules::id($id);return $this->access($collection,function(array &$data)use($id):bool{$found=isset($data[$id]);unset($data[$id]);return $found;},true);
    }
    public function find(string $collection,array $equals=[],int $limit=100): array
    {
        DocumentRules::filter($equals,$limit);
        return $this->access($collection,function(array $data)use($equals,$limit):array{$found=[];foreach($data as $document){foreach($equals as $key=>$value){if(!array_key_exists($key,$document) || $document[$key]!==$value){continue 2;}}$found[]=$document;if(count($found)>=$limit){break;}}return $found;});
    }
}
