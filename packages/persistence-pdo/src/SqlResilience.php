<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\{CircuitPermit,ResilienceGate};

/** Atomic SQL circuit + fixed-window retry quota. Use a dedicated connection and destination name. */
final readonly class SqlResilience implements ResilienceGate
{
    private string $mutex;
    private string $states;
    private string $resource;
    private string $configuration;
    private \Closure $clock;

    public function __construct(private SqlConnection $db,string $destination,private int $failureThreshold=5,
        private int $cooldownSeconds=30,private int $probeSeconds=30,private int $retryCapacity=100,
        private int $retryWindowSeconds=60,string $prefix='cv_resilience',?callable $clock=null)
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D',$destination)||$failureThreshold<1||$failureThreshold>100
            ||$cooldownSeconds<1||$cooldownSeconds>3600||$probeSeconds<1||$probeSeconds>3600
            ||$retryCapacity<0||$retryCapacity>1000000||$retryWindowSeconds<1||$retryWindowSeconds>86400) {
            throw new \InvalidArgumentException('Invalid resilience policy.');
        }
        $this->mutex=$db->quoteIdentifier($prefix.'_mutex');$this->states=$db->quoteIdentifier($prefix.'_states');
        TransactionalTables::check($db,[$prefix.'_mutex',$prefix.'_states']);
        $this->resource=hash('sha256',$destination);
        $this->configuration=hash('sha256',json_encode([$failureThreshold,$cooldownSeconds,$probeSeconds,$retryCapacity,$retryWindowSeconds],JSON_THROW_ON_ERROR));
        $this->clock=\Closure::fromCallable($clock??function():int {
            $expression=match($this->db->driver()) {'mysql'=>'UNIX_TIMESTAMP()','pgsql'=>'CAST(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP) AS BIGINT)',default=>"CAST(strftime('%s','now') AS INTEGER)"};
            return (int)$this->db->select('SELECT '.$expression.' AS now')[0]['now'];
        });
    }

    public function migrate():void
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Resilience owns its transaction.'); }
        $mysql=$this->db->driver()==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->mutex.' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->mutex.' (id,version) VALUES (1,0)'.($mysql?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->states.' (resource VARCHAR(64) PRIMARY KEY,payload TEXT NOT NULL)'.$tail);
    }

    private function change(callable $work):mixed
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Resilience requires a dedicated connection.'); }
        return $this->db->transaction(function()use($work):mixed {
            if ($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1) { throw new \RuntimeException('Run resilience setup.'); }
            $now=($this->clock)();if (!is_int($now)||$now<0) { throw new \LogicException('Invalid resilience clock.'); }
            $row=$this->db->select('SELECT payload FROM '.$this->states.' WHERE resource=?',[$this->resource])[0]??null;
            $state=$row===null ? ['config'=>$this->configuration,'generation'=>0,'failures'=>0,'open'=>0,'probe'=>'','probe_until'=>0,'clock'=>$now,'period'=>intdiv($now,$this->retryWindowSeconds),'used'=>0]
                : json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            if (($state['config']??null)!==$this->configuration) { throw new \LogicException('Resilience policy drift requires explicit operator reconciliation.'); }
            if ($state['clock']>$now) { throw new \RuntimeException('RESILIENCE_CLOCK_REGRESSION'); }
            $state['clock']=$now;$result=$work($state,$now);
            $wire=json_encode($state,JSON_THROW_ON_ERROR);
            if ($row===null) { $this->db->execute('INSERT INTO '.$this->states.' (resource,payload) VALUES (?,?)',[$this->resource,$wire]); }
            else { $this->db->execute('UPDATE '.$this->states.' SET payload=? WHERE resource=?',[$wire,$this->resource]); }
            return $result;
        });
    }

    public function enter():CircuitPermit
    {
        $permit=$this->change(function(array &$s,int $now):?CircuitPermit {
            if ($s['open']>$now||($s['probe']!==''&&$s['probe_until']>$now)) { return null; }
            if ($s['open']!==0||$s['probe']!=='') {
                $s['generation']++;$s['probe']=bin2hex(random_bytes(16));$s['probe_until']=$now+$this->probeSeconds;
                return new CircuitPermit($this->resource,$s['generation'],$s['probe']);
            }
            return new CircuitPermit($this->resource,$s['generation']);
        });
        return $permit??throw new \RuntimeException('CIRCUIT_OPEN');
    }

    private function finish(CircuitPermit $permit,bool $success):void
    {
        $probe=$permit->consume($this->resource);
        $this->change(function(array &$s,int $now)use($permit,$probe,$success):void {
            if ($s['generation']!==$permit->generation||$s['probe']!==$probe||($probe!==''&&$s['probe_until']<=$now)) { return; }
            if ($success) {
                $s['failures']=0;
                if ($probe!=='') { $s['generation']++;$s['open']=0;$s['probe']='';$s['probe_until']=0; }
            } else {
                $s['failures']++;
                if ($probe!==''||$s['failures']>=$this->failureThreshold) {
                    $s['generation']++;$s['open']=$now+$this->cooldownSeconds;$s['probe']='';$s['probe_until']=0;
                }
            }
        });
    }
    public function succeed(CircuitPermit $permit):void { $this->finish($permit,true); }
    public function fail(CircuitPermit $permit):void { $this->finish($permit,false); }

    /** Reservations are conservative, including cancellations after reservation. */
    public function takeRetry():bool
    {
        return $this->change(function(array &$s,int $now):bool {
            $period=intdiv($now,$this->retryWindowSeconds);
            if ($period!==$s['period']) { $s['period']=$period;$s['used']=0; }
            if ($s['used']>=$this->retryCapacity) { return false; }
            $s['used']++;return true;
        });
    }

    public function status():array
    {
        return $this->change(function(array &$s,int $now):array {
            $period=intdiv($now,$this->retryWindowSeconds);
            return ['state'=>$s['probe']!==''?'half_open':($s['open']!==0?'open':'closed'),
                'failures'=>$s['failures'],'retry_remaining'=>$this->retryCapacity-($period===$s['period']?$s['used']:0),
                'retry_window_ends'=>($period+1)*$this->retryWindowSeconds];
        });
    }
}
