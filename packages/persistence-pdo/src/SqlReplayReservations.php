<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** Atomic multi-process replay reservations; applications still own post-reservation crash reconciliation. */
final readonly class SqlReplayReservations
{
    private string $table;
    public function __construct(private SqlConnection $db,string $table='cv_webhook_replay')
    {
        $this->table=$db->quoteIdentifier($table);TransactionalTables::check($db,[$table]);
    }
    public function migrate():void
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Replay setup needs its own connection.'); }
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table.' (digest VARCHAR(64) PRIMARY KEY,expires BIGINT NOT NULL)'.($this->db->driver()==='mysql'?' ENGINE=InnoDB':''));
    }
    public function reserve(string $digest,int $expires,int $now):bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$digest)||$now<0||$expires<=$now||$expires>$now+3600) { throw new \InvalidArgumentException('Invalid replay reservation.'); }
        if ($this->db->inTransaction()) { throw new \LogicException('Reserve replay state on a dedicated connection.'); }
        return $this->db->transaction(function()use($digest,$expires,$now):bool {
            $this->db->execute('DELETE FROM '.$this->table.' WHERE digest=? AND expires<=?',[$digest,$now]);
            $mysql=$this->db->driver()==='mysql';
            return $this->db->execute('INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->table.' (digest,expires) VALUES (?,?)'.($mysql?'':' ON CONFLICT (digest) DO NOTHING'),[$digest,$expires])->rowCount()===1;
        });
    }
    /** Explicit bounded cleanup; never truncates unexpired reservations. */
    public function prune(int $now,int $limit=100):int
    {
        if ($now<0||$limit<1||$limit>500||$this->db->inTransaction()) { throw new \InvalidArgumentException('Invalid replay cleanup.'); }
        $rows=$this->db->select('SELECT digest FROM '.$this->table.' WHERE expires<=? ORDER BY expires,digest LIMIT '.$limit,[$now]);$count=0;
        foreach ($rows as $row) { $count+=$this->db->execute('DELETE FROM '.$this->table.' WHERE digest=? AND expires<=?',[$row['digest'],$now])->rowCount(); }
        return $count;
    }
}
