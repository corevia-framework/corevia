<?php
declare(strict_types=1);
namespace Corevia\Persistence;
/** One bounded aggregate, updated with a version predicate and majority acknowledgement. */
final readonly class MongoSnapshotStore implements SnapshotStore
{
    private \MongoDB\Driver\Manager $manager;
    public function __construct(string $uri,private string $database,private string $collection='cv_auth',private string $scope='main')
    {
        if(!extension_loaded('mongodb')){throw new \RuntimeException('Enable ext-mongodb for MongoDB auth.');}DocumentRules::collection($database);DocumentRules::collection($collection);DocumentRules::id($scope);
        try{$this->manager=new \MongoDB\Driver\Manager($uri,['serverSelectionTimeoutMS'=>5000,'connectTimeoutMS'=>5000,'socketTimeoutMS'=>5000,'retryWrites'=>true]);}catch(\Throwable){throw new \RuntimeException('MongoDB auth connection could not be configured.');}
    }
    public function read():array
    {
        try{$cursor=$this->manager->executeQuery($this->database.'.'.$this->collection,new \MongoDB\Driver\Query(['_id'=>$this->scope],['limit'=>1,'maxTimeMS'=>5000,'readConcern'=>new \MongoDB\Driver\ReadConcern('majority')]),['readPreference'=>new \MongoDB\Driver\ReadPreference(\MongoDB\Driver\ReadPreference::PRIMARY)]);$cursor->setTypeMap(['root'=>'array']);$row=$cursor->toArray()[0]??null;}catch(\Throwable){throw new \RuntimeException('MongoDB auth read failed.');}
        if($row===null){return ['version'=>0,'payload'=>null];}$version=$row['version']??null;if($version instanceof \MongoDB\BSON\Int64){$number=(string)$version;if(!ctype_digit($number)||strlen($number)>strlen((string)PHP_INT_MAX)||(strlen($number)===strlen((string)PHP_INT_MAX)&&strcmp($number,(string)PHP_INT_MAX)>0)){throw new \RuntimeException('MongoDB auth version exceeds the native integer range.');}$version=(int)$number;}if(!is_int($version)||$version<1||$version>=PHP_INT_MAX||!is_string($row['payload']??null)){throw new \RuntimeException('Invalid MongoDB auth aggregate.');}return ['version'=>$version,'payload'=>$row['payload']];
    }
    public function compareExchange(int $version,string $payload):bool
    {
        if(strlen($payload)>8388608){throw new \LengthException('Auth aggregate exceeds 8 MiB.');}$bulk=new \MongoDB\Driver\BulkWrite();
        if($version===0){$bulk->insert(['_id'=>$this->scope,'version'=>1,'payload'=>$payload]);}else{$bulk->update(['_id'=>$this->scope,'version'=>$version],['$set'=>['payload'=>$payload],'$inc'=>['version'=>1]],['multi'=>false]);}
        try{$result=$this->manager->executeBulkWrite($this->database.'.'.$this->collection,$bulk,['writeConcern'=>new \MongoDB\Driver\WriteConcern('majority',5000,true)]);return $version===0?$result->getInsertedCount()===1:$result->getModifiedCount()===1;}
        catch(\MongoDB\Driver\Exception\BulkWriteException $error){if($version===0){foreach($error->getWriteResult()->getWriteErrors() as $failure){if($failure->getCode()===11000){return false;}}}throw new \RuntimeException('MongoDB auth commit failed.');}catch(\Throwable){throw new \RuntimeException('MongoDB auth commit failed.');}
    }
}
