<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\{TenantScope,ToolApprovals};

/** One-use requests; raw arguments are deliberately excluded from the approval database. */
final readonly class SqlToolApprovals implements ToolApprovals
{
    private string $table;
    private string $mutex;
    public function __construct(private SqlConnection $db,string $prefix='cv_tool_approvals')
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$prefix)) { throw new \InvalidArgumentException('Invalid approval prefix.'); }
        $this->table=$db->quoteIdentifier($prefix.'_requests');$this->mutex=$db->quoteIdentifier($prefix.'_mutex');TransactionalTables::check($db,[$prefix.'_requests',$prefix.'_mutex']);
    }
    public function migrate():void
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Approval setup requires a dedicated connection.'); }
        $mysql=$this->db->driver()==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->mutex.' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->mutex.' (id,version) VALUES (1,0)'.($mysql?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table.' (id VARCHAR(32) PRIMARY KEY,tenant VARCHAR(64) NOT NULL,actor VARCHAR(64) NOT NULL,fingerprint VARCHAR(64) NOT NULL,state VARCHAR(16) NOT NULL,approver VARCHAR(64) NULL,expires BIGINT NOT NULL)'.$tail);
    }
    private function locked(callable $work):mixed
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Approvals require a dedicated connection.'); }
        return $this->db->transaction(function()use($work):mixed {
            if ($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1) { throw new \RuntimeException('Run approval setup.'); }return $work();
        });
    }
    private function input(string $requestId,string $fingerprint,int $now):void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$requestId)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||$now<0) { throw new \InvalidArgumentException('Invalid approval identity.'); }
    }
    public function propose(TenantScope $scope,string $fingerprint,bool $humanApproval,int $now,int $seconds=300):string
    {
        $scope->requireAbility('ai.tools.request');$id=bin2hex(random_bytes(16));$this->input($id,$fingerprint,$now);
        if ($seconds<30||$seconds>3600) { throw new \InvalidArgumentException('Approval lifetime must be 30..3600 seconds.'); }
        return $this->locked(function()use($scope,$fingerprint,$humanApproval,$now,$seconds,$id):string {
            $tenant=$scope->namespace('ai.tool-approvals');
            if ((int)$this->db->select('SELECT COUNT(*) AS total FROM '.$this->table.' WHERE tenant=? AND expires>? AND state IN (?,?)',[$tenant,$now,'pending','approved'])[0]['total']>=1000) { throw new \LengthException('Too many pending tenant tool calls.'); }
            $this->db->execute('INSERT INTO '.$this->table.' (id,tenant,actor,fingerprint,state,approver,expires) VALUES (?,?,?,?,?,?,?)',[$id,$tenant,hash('sha256',$scope->actor),$fingerprint,$humanApproval?'pending':'approved',$humanApproval?null:'policy',$now+$seconds]);return $id;
        });
    }
    public function approve(TenantScope $approver,string $requestId,string $expectedFingerprint,int $now):void
    {
        $approver->requireAbility('ai.tools.approve');$this->input($requestId,$expectedFingerprint,$now);
        $this->locked(function()use($approver,$requestId,$expectedFingerprint,$now):void {
            $row=$this->db->select('SELECT * FROM '.$this->table.' WHERE tenant=? AND id=?',[$approver->namespace('ai.tool-approvals'),$requestId])[0]??null;
            if ($row===null||$row['state']!=='pending'||(int)$row['expires']<=$now||!hash_equals($row['fingerprint'],$expectedFingerprint)||hash_equals($row['actor'],hash('sha256',$approver->actor))) { throw new \RuntimeException('TOOL_APPROVAL_DENIED'); }
            $this->db->execute('UPDATE '.$this->table.' SET state=?,approver=? WHERE id=?',['approved',hash('sha256',$approver->actor),$requestId]);
        });
    }
    public function consume(TenantScope $scope,string $requestId,string $fingerprint,int $now):bool
    {
        $scope->requireAbility('ai.tools.execute');$this->input($requestId,$fingerprint,$now);
        return $this->locked(fn():bool=>$this->db->execute('UPDATE '.$this->table.' SET state=? WHERE tenant=? AND actor=? AND id=? AND fingerprint=? AND state=? AND expires>?',
            ['consumed',$scope->namespace('ai.tool-approvals'),hash('sha256',$scope->actor),$requestId,$fingerprint,'approved',$now])->rowCount()===1);
    }
    /** Explicit receipt retention: only requests expired for at least one day, at most 500 per call. */
    public function prune(TenantScope $operator,int $now,int $limit=100):int
    {
        $operator->requireAbility('ai.tools.approve');if ($now<86400||$limit<1||$limit>500) { throw new \InvalidArgumentException('Invalid approval retention.'); }
        return $this->locked(function()use($operator,$now,$limit):int {
            $tenant=$operator->namespace('ai.tool-approvals');$rows=$this->db->select('SELECT id FROM '.$this->table.' WHERE tenant=? AND expires<=? ORDER BY expires,id LIMIT '.$limit,[$tenant,$now-86400]);
            foreach ($rows as $row) { $this->db->execute('DELETE FROM '.$this->table.' WHERE tenant=? AND id=? AND expires<=?',[$tenant,$row['id'],$now-86400]); }return count($rows);
        });
    }
}
