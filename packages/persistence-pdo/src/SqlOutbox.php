<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\EventOutbox;

/** One database transaction for application writes and events; delivery is at least once. */
final readonly class SqlOutbox implements EventOutbox
{
    private string $table;
    private string $history;
    private string $operations;
    public function __construct(private SqlConnection $db,string $table='cv_app_outbox'){if(strlen($table)>48){throw new \InvalidArgumentException('Outbox table name must fit its companion tables.');}TransactionalTables::check($db,[$table,$table.'_attempts',$table.'_operations']);$this->table=$db->quoteIdentifier($table);$this->history=$db->quoteIdentifier($table.'_attempts');$this->operations=$db->quoteIdentifier($table.'_operations');}
    public function migrate():void
    {
        if($this->db->inTransaction()){throw new \LogicException('Outbox schema setup must run outside a transaction.');}
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table.' (id VARCHAR(32) PRIMARY KEY,topic VARCHAR(128) NOT NULL,payload TEXT NOT NULL,status VARCHAR(16) NOT NULL,attempt INTEGER NOT NULL,ready BIGINT NOT NULL,lease BIGINT NOT NULL,owner VARCHAR(32) NOT NULL)'.($this->db->driver()==='mysql'?' ENGINE=InnoDB':''));
        $tail=$this->db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->history.' (delivery_id VARCHAR(32) PRIMARY KEY,event_id VARCHAR(32) NOT NULL,attempt INTEGER NOT NULL,started BIGINT NOT NULL,finished BIGINT NULL,outcome VARCHAR(16) NOT NULL,error_type VARCHAR(255) NULL)'.$tail);
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->operations.' (request_id VARCHAR(32) PRIMARY KEY,event_id VARCHAR(32) NOT NULL,requested BIGINT NOT NULL)'.$tail);
    }
    /** Callback must use the supplied connection; no external effects or DDL. */
    public function commit(string $topic,array $event,callable $applicationWrite,int $now):array
    {
        if(!preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$topic)){throw new \InvalidArgumentException('Invalid event topic.');}
        $wire=json_encode($event,JSON_THROW_ON_ERROR,32);if(strlen($wire)>16384){throw new \LengthException('Outbox event exceeds 16 KiB.');}
        $id=bin2hex(random_bytes(16));
        return $this->db->transaction(function()use($topic,$wire,$id,$applicationWrite,$now):array{
            $result=$applicationWrite($this->db);
            $this->db->execute('INSERT INTO '.$this->table.' (id,topic,payload,status,attempt,ready,lease,owner) VALUES (?,?,?, ?,0,?,0,?)',[$id,$topic,$wire,'pending',$now,'']);
            return ['event_id'=>$id,'result'=>$result];
        });
    }
    /** Sender must be bounded/idempotent. Durable attempt intent commits before any outbound work. */
    public function relay(callable $send,int $now,int $leaseSeconds=60,int $maxAttempts=6):bool
    {
        if($this->db->inTransaction()){throw new \LogicException('Relay needs a dedicated connection outside application transactions.');}
        if($leaseSeconds<10||$leaseSeconds>3600||$maxAttempts<1||$maxAttempts>10){throw new \InvalidArgumentException('Invalid relay bounds.');}
        // Avoid taking a write lock on every idle poll. Only reconcile observed exhausted claims.
        $expired=$this->db->select('SELECT id,owner FROM '.$this->table.' WHERE status=? AND lease<=? AND attempt>=? LIMIT 16',['running',$now,$maxAttempts]);
        foreach($expired as $lost){$this->db->transaction(function()use($lost,$now,$maxAttempts):void{
            $count=$this->db->execute('UPDATE '.$this->table.' SET status=? WHERE id=? AND owner=? AND status=? AND lease<=? AND attempt>=?',['dead',$lost['id'],$lost['owner'],'running',$now,$maxAttempts])->rowCount();
            if($count===1){$this->db->execute('UPDATE '.$this->history.' SET outcome=?,finished=? WHERE delivery_id=? AND outcome=?',['uncertain',$now,$lost['owner'],'running']);}
        });}
        $rows=$this->db->select('SELECT * FROM '.$this->table.' WHERE (status=? OR (status=? AND lease<=?)) AND ready<=? AND attempt<? ORDER BY ready,id LIMIT 16',['pending','running',$now,$now,$maxAttempts]);
        foreach($rows as $row){
            $owner=bin2hex(random_bytes(16));$attempt=(int)$row['attempt']+1;
            $claimed=$this->db->transaction(function()use($row,$owner,$attempt,$now,$leaseSeconds):bool{
                $count=$this->db->execute('UPDATE '.$this->table.' SET status=?,owner=?,lease=?,attempt=attempt+1 WHERE id=? AND attempt=? AND (status=? OR (status=? AND lease<=?))',['running',$owner,$now+$leaseSeconds,$row['id'],(int)$row['attempt'],'pending','running',$now])->rowCount();
                if($count!==1){return false;}
                // A previous running attempt is uncertain even if its process never returns.
                $this->db->execute('UPDATE '.$this->history.' SET outcome=?,finished=? WHERE event_id=? AND outcome=?',['uncertain',$now,$row['id'],'running']);
                $this->db->execute('INSERT INTO '.$this->history.' (delivery_id,event_id,attempt,started,finished,outcome,error_type) VALUES (?,?,?,?,NULL,?,NULL)',[$owner,$row['id'],$attempt,$now,'running']);return true;
            });
            if(!$claimed){continue;}
            $started=hrtime(true);$failure=null;
            try{\Corevia\Core\WorkContext::run(new \Corevia\Core\Deadline(($leaseSeconds-1)*1000),fn()=>$send(['event_id'=>$row['id'],'topic'=>$row['topic'],'data'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'attempt'=>$attempt]));}
            catch(\Throwable $error){$failure=$error::class;}
            $finished=$now+(int)floor(max(0,hrtime(true)-$started)/1e9);
            $this->db->transaction(function()use($row,$owner,$attempt,$finished,$maxAttempts,$failure):void{
                $next=$failure===null?'done':($attempt>=$maxAttempts?'dead':'pending');
                $count=$this->db->execute('UPDATE '.$this->table.' SET status=?,ready=?,owner=?,lease=0 WHERE id=? AND owner=? AND status=? AND lease>?',[$next,$finished+($failure===null?0:min(3600,5*(2**($attempt-1)))),'',$row['id'],$owner,'running',$finished])->rowCount();
                $outcome=$count!==1?'stale':($failure===null?'delivered':($next==='dead'?'dead':'retry'));
                $this->db->execute('UPDATE '.$this->history.' SET outcome=?,finished=?,error_type=? WHERE delivery_id=?',[$outcome,$finished,$failure,$owner]);
            });return true;
        }return false;
    }
    /** Metadata-only operator view, never event payloads or destination credentials. */
    public function history(string $eventId,int $limit=50):array
    {
        $this->identifier($eventId);if($limit<1||$limit>100){throw new \InvalidArgumentException('History limit must be 1..100.');}
        return $this->db->select('SELECT attempt,started,finished,outcome,error_type FROM '.$this->history.' WHERE event_id=? ORDER BY started,delivery_id LIMIT '.$limit,[$eventId]);
    }
    /** Operator-authorized redelivery only: stable event ID, expected attempt, unique audit request. */
    public function retryDead(string $eventId,int $expectedAttempt,string $requestId,int $now):bool
    {
        $this->identifier($eventId);$this->identifier($requestId);
        if($expectedAttempt<1||$expectedAttempt>10||$this->db->inTransaction()){throw new \InvalidArgumentException('Invalid outbox redelivery.');}
        return $this->db->transaction(function()use($eventId,$expectedAttempt,$requestId,$now):bool{
            if($this->db->select('SELECT request_id FROM '.$this->operations.' WHERE request_id=?',[$requestId])!==[]){return false;}
            $count=$this->db->execute('UPDATE '.$this->table.' SET status=?,attempt=0,ready=?,lease=0,owner=? WHERE id=? AND status=? AND attempt=?',['pending',$now,'',$eventId,'dead',$expectedAttempt])->rowCount();
            if($count!==1){return false;}
            $this->db->execute('INSERT INTO '.$this->operations.' (request_id,event_id,requested) VALUES (?,?,?)',[$requestId,$eventId,$now]);return true;
        });
    }
    private function identifier(string $id):void { if(!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new \InvalidArgumentException('Invalid outbox event/request ID.');} }
    public function status():array
    {
        $result=['pending'=>0,'running'=>0,'done'=>0,'dead'=>0];foreach($this->db->select('SELECT status,COUNT(*) AS total FROM '.$this->table.' GROUP BY status') as $row){$result[$row['status']]=(int)$row['total'];}return $result;
    }
}
