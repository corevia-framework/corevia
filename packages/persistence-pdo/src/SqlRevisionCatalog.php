<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\{RevisionCatalog,TenantScope};

/** Atomic active-revision pointers. Embeddings/vector writes never occur inside catalog transactions. */
final readonly class SqlRevisionCatalog implements RevisionCatalog
{
    private string $documents;
    private string $revisions;
    private string $mutex;
    public function __construct(private SqlConnection $db,string $prefix='cv_knowledge')
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$prefix)) { throw new \InvalidArgumentException('Invalid catalog prefix.'); }
        $this->documents=$db->quoteIdentifier($prefix.'_documents');$this->revisions=$db->quoteIdentifier($prefix.'_revisions');$this->mutex=$db->quoteIdentifier($prefix.'_mutex');
        TransactionalTables::check($db,[$prefix.'_documents',$prefix.'_revisions',$prefix.'_mutex']);
    }
    public function migrate():void
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Catalog setup owns its connection.'); }
        $mysql=$this->db->driver()==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->mutex.' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->mutex.' (id,version) VALUES (1,0)'.($mysql?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->documents.' (tenant VARCHAR(64) NOT NULL,document VARCHAR(100) NOT NULL,version BIGINT NOT NULL,active VARCHAR(32) NULL,PRIMARY KEY(tenant,document))'.$tail);
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->revisions.' (id VARCHAR(32) PRIMARY KEY,tenant VARCHAR(64) NOT NULL,document VARCHAR(100) NOT NULL,expected BIGINT NOT NULL,model VARCHAR(128) NOT NULL,content_hash VARCHAR(64) NOT NULL,chunks INTEGER NOT NULL,state VARCHAR(16) NOT NULL)'.$tail);
    }
    private function locked(callable $work):mixed
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Catalog requires a dedicated connection.'); }
        return $this->db->transaction(function()use($work):mixed {
            if ($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1) { throw new \RuntimeException('Run catalog setup.'); }return $work();
        });
    }
    private function identity(string $value,int $max=100):void { if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/D',$value)||strlen($value)>$max) { throw new \InvalidArgumentException('Invalid document/model identifier.'); } }
    private function revision(TenantScope $scope,string $id):array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new \InvalidArgumentException('Invalid revision ID.'); }
        return $this->db->select('SELECT * FROM '.$this->revisions.' WHERE tenant=? AND id=?',[$scope->namespace('knowledge.catalog'),$id])[0]??throw new \RuntimeException('REVISION_NOT_FOUND');
    }
    public function begin(TenantScope $scope,string $document,int $expectedVersion,string $model,string $contentHash,int $chunks):array
    {
        $scope->requireAbility('ai.documents.write');$this->identity($document);$this->identity($model,128);
        if ($expectedVersion<0||$expectedVersion>=PHP_INT_MAX-1||!preg_match('/^[a-f0-9]{64}$/D',$contentHash)||$chunks<1||$chunks>128) { throw new \InvalidArgumentException('Invalid revision plan.'); }
        $tenant=$scope->namespace('knowledge.catalog');
        return $this->locked(function()use($tenant,$document,$expectedVersion,$model,$contentHash,$chunks):array {
            $doc=$this->db->select('SELECT * FROM '.$this->documents.' WHERE tenant=? AND document=?',[$tenant,$document])[0]??null;
            if ((int)($doc['version']??0)!==$expectedVersion) { throw new \RuntimeException('DOCUMENT_VERSION_CONFLICT'); }
            if ($doc===null) {
                if ((int)$this->db->select('SELECT COUNT(*) AS total FROM '.$this->documents.' WHERE tenant=?',[$tenant])[0]['total']>=128) { throw new \LengthException('Catalog holds at most 128 document identities per tenant.'); }
                $this->db->execute('INSERT INTO '.$this->documents.' (tenant,document,version,active) VALUES (?,?,0,NULL)',[$tenant,$document]);
            }
            if ((int)$this->db->select('SELECT COUNT(*) AS total FROM '.$this->revisions.' WHERE tenant=? AND state=?',[$tenant,'staging'])[0]['total']>=128) { throw new \LengthException('Too many unfinished tenant revisions.'); }
            $id=bin2hex(random_bytes(16));$this->db->execute('INSERT INTO '.$this->revisions.' (id,tenant,document,expected,model,content_hash,chunks,state) VALUES (?,?,?,?,?,?,?,?)',[$id,$tenant,$document,$expectedVersion,$model,$contentHash,$chunks,'staging']);
            return ['id'=>$id,'document'=>$document,'expected'=>$expectedVersion,'chunks'=>$chunks,'model'=>$model];
        });
    }
    public function activate(TenantScope $scope,string $revision):array
    {
        $scope->requireAbility('ai.documents.write');return $this->locked(function()use($scope,$revision):array {
            $row=$this->revision($scope,$revision);if ($row['state']!=='staging') { throw new \RuntimeException('REVISION_NOT_STAGING'); }
            $doc=$this->db->select('SELECT * FROM '.$this->documents.' WHERE tenant=? AND document=?',[$row['tenant'],$row['document']])[0];
            if ((int)$doc['version']!==(int)$row['expected']) { throw new \RuntimeException('DOCUMENT_VERSION_CONFLICT'); }
            if ($doc['active']!==null) { $this->db->execute('UPDATE '.$this->revisions.' SET state=? WHERE id=?',['retired',$doc['active']]); }
            $version=(int)$doc['version']+1;$this->db->execute('UPDATE '.$this->documents.' SET active=?,version=? WHERE tenant=? AND document=?',[$revision,$version,$row['tenant'],$row['document']]);
            $this->db->execute('UPDATE '.$this->revisions.' SET state=? WHERE id=?',['active',$revision]);return ['document'=>$row['document'],'revision'=>$revision,'version'=>$version];
        });
    }
    public function abandon(TenantScope $scope,string $revision):bool
    {
        $scope->requireAbility('ai.documents.write');return $this->locked(function()use($scope,$revision):bool {
            $row=$this->revision($scope,$revision);if ($row['state']==='active') { return false; }
            if ($row['state']==='staging') { $this->db->execute('UPDATE '.$this->revisions.' SET state=? WHERE id=?',['failed',$revision]); }
            return in_array($row['state'],['staging','failed','retired','collected'],true);
        });
    }
    public function active(TenantScope $scope):array
    {
        $scope->requireAbility('ai.documents.read');
        return $this->db->select('SELECT r.id,r.document,r.model,r.chunks,d.version FROM '.$this->revisions.' r JOIN '.$this->documents.' d ON d.tenant=r.tenant AND d.document=r.document AND d.active=r.id WHERE r.tenant=? AND r.state=? ORDER BY r.id LIMIT 129',[$scope->namespace('knowledge.catalog'),'active']);
    }
    public function delete(TenantScope $scope,string $document,int $expectedVersion):array
    {
        $scope->requireAbility('ai.documents.write');$this->identity($document);if ($expectedVersion<1||$expectedVersion>=PHP_INT_MAX-1) { throw new \InvalidArgumentException('Use a current document version.'); }
        $tenant=$scope->namespace('knowledge.catalog');return $this->locked(function()use($tenant,$document,$expectedVersion):array {
            $doc=$this->db->select('SELECT * FROM '.$this->documents.' WHERE tenant=? AND document=?',[$tenant,$document])[0]??null;
            if ($doc===null||(int)$doc['version']!==$expectedVersion) { throw new \RuntimeException('DOCUMENT_VERSION_CONFLICT'); }
            if ($doc['active']!==null) { $this->db->execute('UPDATE '.$this->revisions.' SET state=? WHERE id=?',['retired',$doc['active']]); }
            $this->db->execute('UPDATE '.$this->documents.' SET active=NULL,version=version+1 WHERE tenant=? AND document=?',[$tenant,$document]);return ['document'=>$document,'version'=>$expectedVersion+1,'deleted'=>true];
        });
    }
    public function collectable(TenantScope $scope,int $limit=20):array
    {
        $scope->requireAbility('ai.documents.write');if ($limit<1||$limit>100) { throw new \InvalidArgumentException('Choose 1..100 revisions.'); }
        return $this->db->select('SELECT id FROM '.$this->revisions.' WHERE tenant=? AND state IN (?,?) ORDER BY id LIMIT '.$limit,[$scope->namespace('knowledge.catalog'),'failed','retired']);
    }
    public function collected(TenantScope $scope,string $revision):void
    {
        $scope->requireAbility('ai.documents.write');$this->locked(function()use($scope,$revision):void {
            $row=$this->revision($scope,$revision);if (!in_array($row['state'],['failed','retired','collected'],true)) { throw new \LogicException('Cannot collect active or in-progress revision.'); }
            $this->db->execute('UPDATE '.$this->revisions.' SET state=? WHERE id=?',['collected',$revision]);
        });
    }
}
