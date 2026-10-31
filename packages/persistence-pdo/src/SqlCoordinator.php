<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\{JobClaim,WorkQueue};
/** Shared SQL leases/queue. Automatic retries require idempotent handlers. */
final readonly class SqlCoordinator implements WorkQueue
{
    public function __construct(private SqlConnection $db,private string $prefix='cv_work',private int $attempts=6,private int $ttl=86400)
    {
        if(!in_array($db->driver(),['sqlite','mysql','pgsql'],true)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$prefix)||$attempts<1||$attempts>10||$ttl<60||$ttl>604800){throw new \InvalidArgumentException('Invalid SQL coordination settings.');}
        if($db->driver()==='mysql'){$rows=$db->select('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (:mutex,:jobs,:leases)',['mutex'=>$prefix.'_mutex','jobs'=>$prefix.'_jobs','leases'=>$prefix.'_leases']);foreach($rows as $row){if(strtoupper($row['engine']??'')!=='INNODB'){throw new \LogicException('Coordination tables must use InnoDB.');}}}
    }
    private function table(string $name):string{return $this->db->quoteIdentifier($this->prefix.'_'.$name);}
    private function available():void{if($this->db->inTransaction()){throw new \LogicException('Coordination owns its transaction; use a dedicated connection.');}}
    public function clock():int{$this->available();$expression=match($this->db->driver()){'mysql'=>'UNIX_TIMESTAMP()','pgsql'=>'CAST(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP) AS BIGINT)',default=>"CAST(strftime('%s','now') AS INTEGER)"};return (int)$this->db->select('SELECT '.$expression.' AS server_time')[0]['server_time'];}
    public function migrate():void
    {
        $this->available();$mysql=$this->db->driver()==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';$text=$mysql?'LONGTEXT':'TEXT';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('mutex').' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->table('mutex').' (id,version) VALUES (1,0)'.($mysql?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('jobs').' (id VARCHAR(32) PRIMARY KEY,task VARCHAR(128) NOT NULL,input '.$text.' NOT NULL,result '.$text.' NULL,status VARCHAR(16) NOT NULL,ready BIGINT NOT NULL,expires BIGINT NOT NULL,lease BIGINT NOT NULL,owner VARCHAR(32) NOT NULL,attempt INTEGER NOT NULL'.($mysql?', INDEX (status,ready), INDEX (expires)':'').')'.$tail);
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('leases').' (resource VARCHAR(64) PRIMARY KEY,owner VARCHAR(32) NOT NULL,fence BIGINT NOT NULL,expires BIGINT NOT NULL)'.$tail);
        if(!$mysql){$this->db->execute('CREATE INDEX IF NOT EXISTS '.$this->db->quoteIdentifier($this->prefix.'_ready_idx').' ON '.$this->table('jobs').' (status,ready)');$this->db->execute('CREATE INDEX IF NOT EXISTS '.$this->db->quoteIdentifier($this->prefix.'_expiry_idx').' ON '.$this->table('jobs').' (expires)');}
    }
    private function locked(callable $work):mixed{$this->available();return $this->db->transaction(function()use($work):mixed{if($this->db->execute('UPDATE '.$this->table('mutex').' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1){throw new \RuntimeException('Run coordination:migrate.');}return $work();});}
    private function seconds(int $seconds):void{if($seconds<10||$seconds>3600){throw new \InvalidArgumentException('Lease lifetime must be 10..3600 seconds.');}}
    private function json(array $value):string{$json=json_encode($value,JSON_THROW_ON_ERROR);if(strlen($json)>1048576){throw new \LengthException('Queue payload exceeds 1 MiB.');}return $json;}
    public function dispatch(string $task,array $input,bool $idempotent,int $now):string
    {
        if(!$idempotent||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$task)){throw new \InvalidArgumentException('Shared queue tasks must be named and explicitly idempotent.');}$json=$this->json($input);$id=bin2hex(random_bytes(16));return $this->locked(function()use($task,$json,$now,$id):string{$this->db->execute('INSERT INTO '.$this->table('jobs').' (id,task,input,result,status,ready,expires,lease,owner,attempt) VALUES (:id,:task,:input,NULL,:status,:ready,:expires,0,:owner,0)',['id'=>$id,'task'=>$task,'input'=>$json,'status'=>'pending','ready'=>$now,'expires'=>$now+$this->ttl,'owner'=>'']);return $id;});
    }
    public function claim(int $now,int $seconds=60):?JobClaim
    {
        $this->seconds($seconds);return $this->locked(function()use($now,$seconds):?JobClaim{
            $this->db->execute('DELETE FROM '.$this->table('jobs').' WHERE expires<=:now',['now'=>$now]);$this->db->execute('UPDATE '.$this->table('jobs').' SET status=:dead WHERE status=:running AND lease<=:now AND attempt>=:attempts',['dead'=>'dead','running'=>'running','now'=>$now,'attempts'=>$this->attempts]);
            $row=$this->db->select('SELECT * FROM '.$this->table('jobs').' WHERE (status=:pending OR (status=:running AND lease<=:now)) AND ready<=:ready AND attempt<:attempts ORDER BY ready,id LIMIT 1',['pending'=>'pending','running'=>'running','now'=>$now,'ready'=>$now,'attempts'=>$this->attempts])[0]??null;if($row===null){return null;}$owner=bin2hex(random_bytes(16));$attempt=(int)$row['attempt']+1;
            $this->db->execute('UPDATE '.$this->table('jobs').' SET status=:status,owner=:owner,lease=:lease,attempt=:attempt WHERE id=:id',['status'=>'running','owner'=>$owner,'lease'=>$now+$seconds,'attempt'=>$attempt,'id'=>$row['id']]);return new JobClaim($row['id'],$row['task'],$attempt,json_decode($row['input'],true,64,JSON_THROW_ON_ERROR),$owner);
        });
    }
    public function renew(JobClaim $claim,int $now,int $seconds=60):bool{$this->seconds($seconds);return $this->locked(fn():bool=>$this->db->execute('UPDATE '.$this->table('jobs').' SET lease=:lease WHERE id=:id AND owner=:owner AND status=:status AND lease>:now AND expires>:expiry',['lease'=>$now+$seconds,'id'=>$claim->id,'owner'=>$claim->token(),'status'=>'running','now'=>$now,'expiry'=>$now])->rowCount()===1);}
    public function complete(JobClaim $claim,array $result,int $now):bool{$json=$this->json($result);return $this->locked(fn():bool=>$this->db->execute('UPDATE '.$this->table('jobs').' SET status=:done,result=:result,owner=:empty,lease=0 WHERE id=:id AND owner=:owner AND status=:running AND lease>:now AND expires>:expiry',['done'=>'done','result'=>$json,'empty'=>'','id'=>$claim->id,'owner'=>$claim->token(),'running'=>'running','now'=>$now,'expiry'=>$now])->rowCount()===1);}
    public function fail(JobClaim $claim,int $now,bool $permanent=false):bool{return $this->locked(fn():bool=>$this->db->execute('UPDATE '.$this->table('jobs').' SET status=:next,ready=:ready,owner=:empty,lease=0 WHERE id=:id AND owner=:owner AND status=:running AND lease>:now AND expires>:expiry',['next'=>$permanent||$claim->attempt>=$this->attempts?'dead':'pending','ready'=>$now+min(3600,5*(2**($claim->attempt-1))),'empty'=>'','id'=>$claim->id,'owner'=>$claim->token(),'running'=>'running','now'=>$now,'expiry'=>$now])->rowCount()===1);}
    public function status():array{$this->available();$result=['pending'=>0,'running'=>0,'done'=>0,'dead'=>0];foreach($this->db->select('SELECT status,COUNT(*) AS count FROM '.$this->table('jobs').' GROUP BY status') as $row){$result[$row['status']]=(int)$row['count'];}return $result;}
    public function acquire(string $resource,int $now,int $seconds=60):?CoordinationLease
    {
        $this->seconds($seconds);if($resource===''||strlen($resource)>512){throw new \InvalidArgumentException('Invalid lease resource.');}$key=hash('sha256',$resource);
        return $this->locked(function()use($key,$now,$seconds):?CoordinationLease{$row=$this->db->select('SELECT * FROM '.$this->table('leases').' WHERE resource=:key',['key'=>$key])[0]??null;if($row!==null&&(int)$row['expires']>$now){return null;}$owner=bin2hex(random_bytes(16));$fence=(int)($row['fence']??0)+1;if($row===null){$this->db->execute('INSERT INTO '.$this->table('leases').' (resource,owner,fence,expires) VALUES (:key,:owner,:fence,:expires)',['key'=>$key,'owner'=>$owner,'fence'=>$fence,'expires'=>$now+$seconds]);}else{$this->db->execute('UPDATE '.$this->table('leases').' SET owner=:owner,fence=:fence,expires=:expires WHERE resource=:key',['owner'=>$owner,'fence'=>$fence,'expires'=>$now+$seconds,'key'=>$key]);}return new CoordinationLease($key,$fence,$owner);});
    }
    public function release(CoordinationLease $lease,int $now):bool{return $this->locked(fn():bool=>$this->db->execute('UPDATE '.$this->table('leases').' SET expires=0,owner=:empty WHERE resource=:key AND owner=:owner AND fence=:fence AND expires>:now',['empty'=>'','key'=>$lease->key,'owner'=>$lease->owner(),'fence'=>$lease->fence,'now'=>$now])->rowCount()===1);}
}
