<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\TenantScope;

/** Conservative application units, not a promise about a provider's monetary billing. */
final readonly class SqlUsageBudget
{
    private string $buckets;
    private string $mutex;
    public function __construct(private SqlConnection $db,private int $capacity,private int $periodSeconds=86400,string $prefix='cv_ai_budget')
    {
        if($capacity<1||$capacity>1000000000||$periodSeconds<60||$periodSeconds>2592000){throw new \InvalidArgumentException('Invalid usage budget.');}
        $this->buckets=$db->quoteIdentifier($prefix.'_buckets');$this->mutex=$db->quoteIdentifier($prefix.'_mutex');
        TransactionalTables::check($db,[$prefix.'_buckets',$prefix.'_mutex']);
    }
    public function migrate():void
    {
        if($this->db->inTransaction()){throw new \LogicException('Budget setup must run outside application transactions.');}
        $tail=$this->db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->mutex.' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($this->db->driver()==='mysql'?'IGNORE ':'').'INTO '.$this->mutex.' (id,version) VALUES (1,0)'.($this->db->driver()==='mysql'?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->buckets.' (id VARCHAR(64) PRIMARY KEY,used BIGINT NOT NULL,capacity BIGINT NOT NULL)'.$tail);
    }
    /** Reservations survive cancellation, provider errors and uncertain outcomes; no automatic refund. */
    public function reserve(TenantScope $scope,int $units,int $now):array
    {
        $scope->check();if($units<1||$units>$this->capacity||$now<0){throw new \InvalidArgumentException('Invalid budget reservation.');}
        if($this->db->inTransaction()){throw new \LogicException('Reserve usage on a dedicated connection before provider work.');}
        $period=intdiv($now,$this->periodSeconds);$id=$scope->namespace('ai-budget:'.$this->periodSeconds.':'.$period);
        return $this->db->transaction(function()use($id,$units,$period):array{
            if($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1){throw new \RuntimeException('Run budget schema setup.');}
            $row=$this->db->select('SELECT * FROM '.$this->buckets.' WHERE id=?',[$id])[0]??null;
            if($row===null){$this->db->execute('INSERT INTO '.$this->buckets.' (id,used,capacity) VALUES (?,0,?)',[$id,$this->capacity]);}
            elseif((int)$row['capacity']!==$this->capacity){throw new \LogicException('Changing an active budget requires explicit operator reconciliation.');}
            if($this->db->execute('UPDATE '.$this->buckets.' SET used=used+? WHERE id=? AND used<=?',[$units,$id,$this->capacity-$units])->rowCount()!==1){throw new \RuntimeException('TENANT_BUDGET_EXHAUSTED');}
            $used=(int)($row['used']??0)+$units;return ['reserved'=>$units,'used'=>$used,'remaining'=>$this->capacity-$used,'resets_at'=>($period+1)*$this->periodSeconds];
        });
    }
}
