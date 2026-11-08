<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Persistence\{SqlConnection,TransactionalTables};

/** Bounded exact-search reference. Qdrant supplies the optional service-backed search path. */
final readonly class SqlRevisionVectors implements RevisionVectorIndex
{
    private string $table;
    public function __construct(private SqlConnection $db,string $table='cv_revision_vectors') { $this->table=$db->quoteIdentifier($table);TransactionalTables::check($db,[$table]); }
    public function migrate():void
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Vector setup needs its own connection.'); }
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table.' (tenant VARCHAR(128) NOT NULL,revision VARCHAR(32) NOT NULL,id VARCHAR(128) NOT NULL,body TEXT NOT NULL,vector '.($this->db->driver()==='mysql'?'LONGTEXT':'TEXT').' NOT NULL,PRIMARY KEY(tenant,revision,id))'.($this->db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':''));
    }
    public function putRevision(string $tenant,string $revision,string $id,string $text,array $vector):void
    {
        VectorRules::identity($tenant,$id);VectorRules::vector($vector);$this->revision($revision);
        if (strlen($text)>16384||preg_match('//u',$text)!==1) { throw new \InvalidArgumentException('Use bounded UTF-8 text.'); }
        $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE body=VALUES(body),vector=VALUES(vector)':' ON CONFLICT (tenant,revision,id) DO UPDATE SET body=excluded.body,vector=excluded.vector';
        $this->db->execute('INSERT INTO '.$this->table.' (tenant,revision,id,body,vector) VALUES (?,?,?,?,?)'.$suffix,[$tenant,$revision,$id,$text,json_encode($vector,JSON_THROW_ON_ERROR)]);
    }
    private function revision(string $revision):void { if (!preg_match('/^[a-f0-9]{32}$/D',$revision)) { throw new \InvalidArgumentException('Invalid vector revision.'); } }
    public function searchRevisions(string $tenant,array $revisions,array $vector,int $limit=5):array
    {
        VectorRules::identity($tenant,'query');VectorRules::vector($vector);
        if (!array_is_list($revisions)||count($revisions)>128||$limit<1||$limit>20) { throw new \InvalidArgumentException('Invalid revision search bounds.'); }
        foreach ($revisions as $revision) { $this->revision($revision); }if ($revisions===[]) { return []; }
        $rows=$this->db->select('SELECT id,body,vector FROM '.$this->table.' WHERE tenant=? AND revision IN ('.implode(',',array_fill(0,count($revisions),'?')).') ORDER BY id LIMIT 5001',[$tenant,...$revisions]);
        if (count($rows)>5000) { throw new \LengthException('Exact revision search supports at most 5000 active chunks.'); }
        $memory=new MemoryVectorIndex();foreach ($rows as $row) { $memory->put($tenant,$row['id'],$row['body'],json_decode($row['vector'],true,64,JSON_THROW_ON_ERROR)); }
        return $memory->search($tenant,$vector,$limit);
    }
    public function deleteRevision(string $tenant,string $revision):void
    {
        VectorRules::identity($tenant,'delete');$this->revision($revision);$this->db->execute('DELETE FROM '.$this->table.' WHERE tenant=? AND revision=?',[$tenant,$revision]);
    }
}
