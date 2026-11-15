<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\{CanonicalJson,SyncStore,TenantScope};

/** Optional bounded JSON record store with optimistic revisions and an authenticated change feed. */
final readonly class SqlSyncStore implements SyncStore
{
    private string $records;
    private string $events;
    private string $mutex;
    private string $operations;
    public function __construct(private SqlConnection $db,private string $cursorKey,string $prefix='cv_sync')
    {
        if(strlen($cursorKey)<32){throw new \InvalidArgumentException('Use an independent private cursor signing key.');}
        $this->records=$db->quoteIdentifier($prefix.'_records');$this->events=$db->quoteIdentifier($prefix.'_events');$this->mutex=$db->quoteIdentifier($prefix.'_mutex');$this->operations=$db->quoteIdentifier($prefix.'_operations');
        TransactionalTables::check($db,[$prefix.'_records',$prefix.'_events',$prefix.'_mutex',$prefix.'_operations']);
    }
    public function migrate():void
    {
        if($this->db->inTransaction()){throw new \LogicException('Sync schema setup must run outside application transactions.');}
        $tail=$this->db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->mutex.' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($this->db->driver()==='mysql'?'IGNORE ':'').'INTO '.$this->mutex.' (id,version) VALUES (1,0)'.($this->db->driver()==='mysql'?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->records.' (tenant VARCHAR(128) NOT NULL,id VARCHAR(128) NOT NULL,revision BIGINT NOT NULL,payload TEXT NULL,PRIMARY KEY(tenant,id))'.$tail);
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->events.' (tenant VARCHAR(128) NOT NULL,sequence BIGINT NOT NULL,id VARCHAR(128) NOT NULL,revision BIGINT NOT NULL,payload TEXT NULL,PRIMARY KEY(tenant,sequence))'.$tail);
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->operations.' (tenant VARCHAR(128) NOT NULL,actor VARCHAR(128) NOT NULL,operation_id VARCHAR(32) NOT NULL,digest VARCHAR(64) NOT NULL,response TEXT NOT NULL,PRIMARY KEY(tenant,actor,operation_id))'.$tail);
    }
    private function head(TenantScope $scope):int{return (int)($this->db->select('SELECT MAX(sequence) AS latest FROM '.$this->events.' WHERE tenant=?',[$scope->namespace('sync')])[0]['latest']??0);}
    /** expectedRevision=0 creates. null data is a retained tombstone, never a physical delete. */
    public function mutate(TenantScope $scope,string $id,int $expectedRevision,?array $data):array
    {
        $scope->check();if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$id)||$expectedRevision<0||$expectedRevision>=PHP_INT_MAX-1){throw new \InvalidArgumentException('Invalid sync identifier or revision.');}
        $wire=$data===null?null:json_encode($data,JSON_THROW_ON_ERROR,32);if($wire!==null&&strlen($wire)>16384){throw new \LengthException('Sync record exceeds 16 KiB.');}
        return $this->db->transaction(function()use($scope,$id,$expectedRevision,$wire):array{
            if($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1){throw new \RuntimeException('Run sync schema setup.');}
            $row=$this->db->select('SELECT revision FROM '.$this->records.' WHERE tenant=? AND id=?',[$scope->namespace('sync'),$id])[0]??null;
            if((int)($row['revision']??0)!==$expectedRevision){throw new \RuntimeException('SYNC_REVISION_CONFLICT');}
            $revision=$expectedRevision+1;$head=$this->head($scope);if($head>=1000000){throw new \LengthException('Reference sync store event capacity reached; archive/reset requires an operator migration.');}$sequence=$head+1;
            if($row===null){$this->db->execute('INSERT INTO '.$this->records.' (tenant,id,revision,payload) VALUES (?,?,?,?)',[$scope->namespace('sync'),$id,$revision,$wire]);}
            else{$this->db->execute('UPDATE '.$this->records.' SET revision=?,payload=? WHERE tenant=? AND id=?',[$revision,$wire,$scope->namespace('sync'),$id]);}
            $this->db->execute('INSERT INTO '.$this->events.' (tenant,sequence,id,revision,payload) VALUES (?,?,?,?,?)',[$scope->namespace('sync'),$sequence,$id,$revision,$wire]);
            return ['id'=>$id,'revision'=>$revision,'sequence'=>$sequence,'deleted'=>$wire===null];
        });
    }
    /** Stable, actor/tenant-bound retry receipt commits atomically with the record/feed mutation. */
    public function mutateOnce(TenantScope $scope,string $operationId,string $id,int $expectedRevision,?array $data):array
    {
        $scope->check();if($expectedRevision<0||$expectedRevision>=9007199254740991){throw new \InvalidArgumentException('Use a portable expected revision.');}if(!preg_match('/^[a-f0-9]{32}$/D',$operationId)){throw new \InvalidArgumentException('Use a unique 128-bit operation identifier.');}
        $digest=CanonicalJson::hash([$id,$expectedRevision,$data]);$tenant=$scope->namespace('sync');
        return $this->db->transaction(function()use($scope,$operationId,$id,$expectedRevision,$data,$digest,$tenant):array{
            if($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1){throw new \RuntimeException('Run sync schema setup.');}
            $row=$this->db->select('SELECT digest,response FROM '.$this->operations.' WHERE tenant=? AND actor=? AND operation_id=?',[$tenant,$scope->actor,$operationId])[0]??null;
            if($row!==null){if(!hash_equals($row['digest'],$digest)){throw new \RuntimeException('SYNC_OPERATION_CONFLICT');}return json_decode($row['response'],true,16,JSON_THROW_ON_ERROR);}
            if((int)$this->db->select('SELECT COUNT(*) AS total FROM '.$this->operations.' WHERE tenant=?',[$tenant])[0]['total']>=1000000){throw new \LengthException('Sync receipt capacity reached; offline-client reset requires an operator migration.');}
            $result=['operation_id'=>$operationId,...$this->mutate($scope,$id,$expectedRevision,$data)];
            $this->db->execute('INSERT INTO '.$this->operations.' (tenant,actor,operation_id,digest,response) VALUES (?,?,?,?,?)',[$tenant,$scope->actor,$operationId,$digest,json_encode($result,JSON_THROW_ON_ERROR)]);return $result;
        });
    }
    public function changes(TenantScope $scope,?string $cursor,int $now,int $limit=100):array
    {
        $scope->check();if($limit<1||$limit>500||$now<0){throw new \InvalidArgumentException('Invalid sync feed bounds.');}$after=0;
        if($cursor!==null){
            if(strlen($cursor)>2048||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$cursor,$parts)||!hash_equals(hash_hmac('sha256',$parts[1],$this->cursorKey),$parts[2])){throw new \RuntimeException('Invalid sync cursor.');}
            $decoded=base64_decode(strtr($parts[1],'-_','+/'),true);$value=json_decode($decoded===false?'':$decoded,true,8,JSON_THROW_ON_ERROR);
            if(($value['version']??null)!==1||($value['tenant']??null)!==$scope->namespace('sync')||($value['actor']??null)!==$scope->actor||!is_int($value['expires']??null)||$value['expires']<=$now||!is_int($value['after']??null)||$value['after']<0){throw new \RuntimeException('Expired or mismatched sync cursor.');}$after=$value['after'];
        }
        if($after>$this->head($scope)){throw new \RuntimeException('SYNC_CURSOR_REQUIRES_RESET');}
        $rows=$this->db->select('SELECT sequence,id,revision,payload FROM '.$this->events.' WHERE tenant=? AND sequence>? ORDER BY sequence LIMIT '.($limit+1),[$scope->namespace('sync'),$after]);$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];
        foreach($rows as $row){$after=(int)$row['sequence'];$items[]=['sequence'=>$after,'id'=>$row['id'],'revision'=>(int)$row['revision'],'deleted'=>$row['payload']===null,'data'=>$row['payload']===null?null:json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)];}
        $value=['version'=>1,'tenant'=>$scope->namespace('sync'),'actor'=>$scope->actor,'after'=>$after,'expires'=>$now+86400];$encoded=rtrim(strtr(base64_encode(json_encode($value,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        return ['changes'=>$items,'cursor'=>$encoded.'.'.hash_hmac('sha256',$encoded,$this->cursorKey),'has_more'=>$more];
    }
}
