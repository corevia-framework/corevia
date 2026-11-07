<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** One database transaction for application writes and events; delivery is at least once. */
final readonly class SqlOutbox
{
    private string $table;
    public function __construct(private SqlConnection $db,string $table='cv_app_outbox'){TransactionalTables::check($db,[$table]);$this->table=$db->quoteIdentifier($table);}
    public function migrate():void
    {
        if($this->db->inTransaction()){throw new \LogicException('Outbox schema setup must run outside a transaction.');}
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table.' (id VARCHAR(32) PRIMARY KEY,topic VARCHAR(128) NOT NULL,payload TEXT NOT NULL,status VARCHAR(16) NOT NULL,attempt INTEGER NOT NULL,ready BIGINT NOT NULL,lease BIGINT NOT NULL,owner VARCHAR(32) NOT NULL)'.($this->db->driver()==='mysql'?' ENGINE=InnoDB':''));
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
    /** Sender must be bounded and idempotent using event_id. Unknown outcomes may be redelivered. */
    public function relay(callable $send,int $now,int $leaseSeconds=60,int $maxAttempts=6):bool
    {
        if($this->db->inTransaction()){throw new \LogicException('Relay needs a dedicated connection outside application transactions.');}
        if($leaseSeconds<10||$leaseSeconds>3600||$maxAttempts<1||$maxAttempts>10){throw new \InvalidArgumentException('Invalid relay bounds.');}
        $this->db->execute('UPDATE '.$this->table.' SET status=? WHERE status=? AND lease<=? AND attempt>=?',['dead','running',$now,$maxAttempts]);
        $rows=$this->db->select('SELECT * FROM '.$this->table.' WHERE (status=? OR (status=? AND lease<=?)) AND ready<=? AND attempt<? ORDER BY ready,id LIMIT 16',['pending','running',$now,$now,$maxAttempts]);
        foreach($rows as $row){
            $owner=bin2hex(random_bytes(16));
            $claimed=$this->db->execute('UPDATE '.$this->table.' SET status=?,owner=?,lease=?,attempt=attempt+1 WHERE id=? AND attempt=? AND (status=? OR (status=? AND lease<=?))',['running',$owner,$now+$leaseSeconds,$row['id'],(int)$row['attempt'],'pending','running',$now])->rowCount();
            if($claimed!==1){continue;}
            $attempt=(int)$row['attempt']+1;
            try{$send(['event_id'=>$row['id'],'topic'=>$row['topic'],'data'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'attempt'=>$attempt]);}
            catch(\Throwable){$this->db->execute('UPDATE '.$this->table.' SET status=?,ready=?,owner=?,lease=0 WHERE id=? AND owner=? AND status=?',[$attempt>=$maxAttempts?'dead':'pending',$now+min(3600,5*(2**($attempt-1))),'',$row['id'],$owner,'running']);return true;}
            // The owner fence prevents an expired/reclaimed relay from acknowledging a newer claim.
            $this->db->execute('UPDATE '.$this->table.' SET status=?,owner=?,lease=0 WHERE id=? AND owner=? AND status=?',['done','',$row['id'],$owner,'running']);return true;
        }return false;
    }
    public function status():array
    {
        $result=['pending'=>0,'running'=>0,'done'=>0,'dead'=>0];foreach($this->db->select('SELECT status,COUNT(*) AS total FROM '.$this->table.' GROUP BY status') as $row){$result[$row['status']]=(int)$row['total'];}return $result;
    }
}
