<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\RunStore;

/** Portable flow snapshots for MySQL/InnoDB, PostgreSQL and SQLite. Schema creation is explicit. */
final class SqlRunStore implements RunStore
{
    public function __construct(private readonly SqlConnection $db)
    {
        if(!in_array($db->driver(),['mysql','pgsql','sqlite'],true)){throw new \InvalidArgumentException('Run store supports mysql, pgsql and sqlite.');}
    }
    public function migrate(): void
    {
        $text=$this->db->driver()==='mysql'?'MEDIUMTEXT':'TEXT';$engine=$this->db->driver()==='mysql'?' ENGINE=InnoDB':'';
        $this->db->execute("CREATE TABLE IF NOT EXISTS corevia_sql_runs (run_id VARCHAR(64) PRIMARY KEY, flow_name VARCHAR(128) NOT NULL, snapshot $text NOT NULL, locked INTEGER NOT NULL DEFAULT 0)$engine");
        $flowType=$this->db->driver()==='mysql'?'VARBINARY(128)':'VARCHAR(128)';$keyType=$this->db->driver()==='mysql'?'VARBINARY(255)':'VARCHAR(255)';
        $this->db->execute("CREATE TABLE IF NOT EXISTS corevia_sql_keys (flow_name $flowType NOT NULL, idem_key $keyType NOT NULL, run_id VARCHAR(64) NOT NULL, PRIMARY KEY(flow_name,idem_key))$engine");
    }
    public function begin(array $run,?string $key): array
    {
        if(strlen($run['flow_name'])>128 || ($key!==null && strlen($key)>255)){throw new \InvalidArgumentException('Flow name or idempotency key exceeds storage limits.');}
        return $this->db->transaction(function()use($run,$key):array{
            if($key!==null){
                $insert='INSERT INTO corevia_sql_keys (flow_name,idem_key,run_id) VALUES (?,?,?)';
                $insert.=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE run_id=run_id':' ON CONFLICT(flow_name,idem_key) DO NOTHING';
                $this->db->execute($insert,[$run['flow_name'],$key,$run['run_id']]);
                $select='SELECT run_id FROM corevia_sql_keys WHERE flow_name=? AND idem_key=?'.($this->db->driver()==='sqlite'?'':' FOR UPDATE');
                $id=$this->db->execute($select,[$run['flow_name'],$key])->fetchColumn();
                if($id!==$run['run_id']){return $this->get($id) ?? throw new \RuntimeException('Missing reserved run.');}
            }
            $this->save($run);return $run;
        });
    }
    public function save(array $run): void
    {
        $sql='INSERT INTO corevia_sql_runs (run_id,flow_name,snapshot) VALUES (?,?,?)';
        $sql.=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE snapshot=VALUES(snapshot)':' ON CONFLICT(run_id) DO UPDATE SET snapshot=excluded.snapshot';
        $this->db->execute($sql,[$run['run_id'],$run['flow_name'],json_encode($run,JSON_THROW_ON_ERROR)]);
    }
    public function get(string $runId): ?array
    {
        $snapshot=$this->db->execute('SELECT snapshot FROM corevia_sql_runs WHERE run_id=?',[$runId])->fetchColumn();
        return $snapshot===false?null:json_decode($snapshot,true,512,JSON_THROW_ON_ERROR);
    }
    public function lock(string $runId): bool { return $this->db->execute('UPDATE corevia_sql_runs SET locked=1 WHERE run_id=? AND locked=0',[$runId])->rowCount()===1; }
    public function unlock(string $runId): void { $this->db->execute('UPDATE corevia_sql_runs SET locked=0 WHERE run_id=?',[$runId]); }
}
