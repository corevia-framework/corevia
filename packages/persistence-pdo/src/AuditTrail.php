<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\{CanonicalJson,TenantScope};

/** Append-only HMAC chain. Keep signed checkpoints outside the audit database to detect rollback. */
final readonly class AuditTrail
{
    private string $rows;
    private string $heads;
    private string $mutex;
    private \Closure $clock;
    public function __construct(private SqlConnection $db,private array $keys,private string $active,private array $schemas,
        private int $retentionSeconds=2592000,string $prefix='cv_audit',?callable $clock=null)
    {
        if(!isset($keys[$active])||count($keys)>8||$retentionSeconds<60||$retentionSeconds>315360000||count($schemas)>128){throw new \InvalidArgumentException('Invalid audit key ring or retention policy.');}
        foreach($keys as $name=>$key){if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$name)||!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Use independent named audit keys of at least 32 bytes.');}}
        foreach($schemas as $event=>$fields){
            if(!is_string($event)||!preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D',$event)||!is_array($fields)||count($fields)>16){throw new \InvalidArgumentException('Invalid audit event schema.');}
            foreach($fields as $field=>$type){if(!is_string($field)||!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$field)||!in_array($type,['identifier','integer','boolean'],true)){throw new \InvalidArgumentException('Audit metadata must use declared non-content fields.');}}
        }
        $this->rows=$db->quoteIdentifier($prefix.'_events');$this->heads=$db->quoteIdentifier($prefix.'_heads');$this->mutex=$db->quoteIdentifier($prefix.'_mutex');
        TransactionalTables::check($db,[$prefix.'_events',$prefix.'_heads',$prefix.'_mutex']);$this->clock=\Closure::fromCallable($clock??time(...));
    }
    public function migrate():void
    {
        if($this->db->inTransaction()){throw new \LogicException('Run audit setup outside application transactions.');}
        $mysql=$this->db->driver()==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->mutex.' (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)'.$tail);
        $this->db->execute('INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->mutex.' (id,version) VALUES (1,0)'.($mysql?'':' ON CONFLICT (id) DO NOTHING'));
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->heads.' (stream VARCHAR(64) PRIMARY KEY,payload TEXT NOT NULL)'.$tail);
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->rows.' (stream VARCHAR(64) NOT NULL,sequence BIGINT NOT NULL,event_id VARCHAR(64) NOT NULL,payload TEXT NOT NULL,mac VARCHAR(64) NOT NULL,PRIMARY KEY(stream,sequence),UNIQUE(stream,event_id))'.$tail);
    }
    private function locked(callable $work):mixed{return $this->db->transaction(function()use($work):mixed{
        if($this->db->execute('UPDATE '.$this->mutex.' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1){throw new \RuntimeException('Run audit schema setup.');}return $work();
    });}
    private function now():int{$now=($this->clock)();if(!is_int($now)||$now<0){throw new \LogicException('Audit clock must return server Unix seconds.');}return $now;}
    private function mac(string $purpose,array $value,string $key):string
    {
        if(!isset($this->keys[$key])){throw new \RuntimeException('A retained audit verification key is unavailable.');}
        return hash_hmac('sha256',$purpose.'|'.CanonicalJson::encode($value),$this->keys[$key]);
    }
    private function head(string $stream):array
    {
        $row=$this->db->select('SELECT payload FROM '.$this->heads.' WHERE stream=?',[$stream])[0]??null;
        if($row===null){
            if($this->db->select('SELECT sequence FROM '.$this->rows.' WHERE stream=? LIMIT 1',[$stream])!==[]){throw new \RuntimeException('Audit chain head is missing.');}
            return ['stream'=>$stream,'sequence'=>0,'mac'=>str_repeat('0',64),'base'=>0,'base_mac'=>str_repeat('0',64),'hold'=>false,'time'=>0,'kid'=>$this->active];
        }
        $head=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);$signature=$head['signature']??'';unset($head['signature']);
        if(($head['stream']??null)!==$stream||!is_string($head['kid']??null)||!is_string($signature)||!hash_equals($this->mac('audit-head',$head,$head['kid']),$signature)
            ||!is_int($head['base']??null)||!is_int($head['sequence']??null)||$head['base']<0||$head['sequence']<$head['base']||!is_bool($head['hold']??null)){throw new \RuntimeException('Audit chain head integrity failed.');}
        return $head;
    }
    private function save(array $head):void
    {
        $head['kid']=$this->active;$head['signature']=$this->mac('audit-head',$head,$this->active);$wire=CanonicalJson::encode($head);
        $this->db->execute('DELETE FROM '.$this->heads.' WHERE stream=?',[$head['stream']]);$this->db->execute('INSERT INTO '.$this->heads.' (stream,payload) VALUES (?,?)',[$head['stream'],$wire]);
    }
    private function decode(array $row,string $stream):array
    {
        $value=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
        if(($value['stream']??null)!==$stream||($value['sequence']??null)!==(int)$row['sequence']||($value['event_id']??null)!==$row['event_id']||!is_string($value['kid']??null)
            ||!hash_equals($this->mac('audit-event',$value,$value['kid']),$row['mac'])){throw new \RuntimeException('Audit event integrity failed.');}return $value;
    }
    public function append(TenantScope $scope,string $event,array $metadata=[],?string $eventId=null):array
    {
        $scope->requireAbility('audit.append');$schema=$this->schemas[$event]??throw new \InvalidArgumentException('Declare the audit event before recording it.');
        if(array_diff(array_keys($metadata),array_keys($schema))){throw new \InvalidArgumentException('Undeclared audit metadata is rejected.');}
        foreach($metadata as $field=>$value){$ok=match($schema[$field]){'integer'=>is_int($value),'boolean'=>is_bool($value),default=>is_string($value)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$value)};if(!$ok){throw new \InvalidArgumentException('Audit fields cannot contain free-form content.');}}
        $eventId??=bin2hex(random_bytes(16));if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D',$eventId)){throw new \InvalidArgumentException('Invalid audit event ID.');}
        $stream=$scope->namespace('audit');
        return $this->locked(function()use($scope,$stream,$event,$metadata,$eventId):array{
            $head=$this->head($stream);$actor=$this->mac('audit-actor',['actor'=>$scope->actor],$this->active);
            $prior=$this->db->select('SELECT * FROM '.$this->rows.' WHERE stream=? AND event_id=?',[$stream,$eventId])[0]??null;
            if($prior!==null){$old=$this->decode($prior,$stream);$oldActor=$this->mac('audit-actor',['actor'=>$scope->actor],$old['kid']);if($old['event']!==$event||$old['actor']!==$oldActor||CanonicalJson::encode($old['metadata'])!==CanonicalJson::encode($metadata)){throw new \LogicException('Audit event ID was reused with different data.');}return ['sequence'=>$old['sequence'],'mac'=>$prior['mac'],'event_id'=>$eventId];}
            if($head['sequence']-$head['base']>=100000){throw new \LengthException('Retained audit capacity reached. Archive according to policy.');}
            $time=$this->now();if($time<$head['time']){throw new \RuntimeException('Audit server clock moved backwards.');}
            $value=['stream'=>$stream,'sequence'=>$head['sequence']+1,'previous'=>$head['mac'],'event_id'=>$eventId,'kid'=>$this->active,'actor'=>$actor,'event'=>$event,'metadata'=>$metadata,'time'=>$time];$mac=$this->mac('audit-event',$value,$this->active);
            $this->db->execute('INSERT INTO '.$this->rows.' (stream,sequence,event_id,payload,mac) VALUES (?,?,?,?,?)',[$stream,$value['sequence'],$eventId,CanonicalJson::encode($value),$mac]);
            $head['sequence']=$value['sequence'];$head['mac']=$mac;$head['time']=$time;$this->save($head);return ['sequence'=>$value['sequence'],'mac'=>$mac,'event_id'=>$eventId];
        });
    }
    private function verifyLocked(string $stream,?array $checkpoint=null):array
    {
        $head=$this->head($stream);$sequence=$head['base'];$mac=$head['base_mac'];$pin=null;
        if($checkpoint!==null){$signature=$checkpoint['signature']??'';unset($checkpoint['signature']);
            if(($checkpoint['stream']??null)!==$stream||!is_string($checkpoint['kid']??null)||!is_string($signature)||!hash_equals($this->mac('audit-checkpoint',$checkpoint,$checkpoint['kid']),$signature)
                ||!is_int($checkpoint['sequence']??null)||$checkpoint['sequence']<$head['base']||$checkpoint['sequence']>$head['sequence']){throw new \RuntimeException('Audit checkpoint mismatch, missing history or rollback.');}
            if($checkpoint['sequence']===$sequence){$pin=$mac;}
        }
        while($sequence<$head['sequence']){
            $batch=$this->db->select('SELECT * FROM '.$this->rows.' WHERE stream=? AND sequence>? ORDER BY sequence LIMIT 500',[$stream,$sequence]);if($batch===[]){throw new \RuntimeException('Audit history is truncated.');}
            foreach($batch as $row){$value=$this->decode($row,$stream);if($value['sequence']!==$sequence+1||$value['previous']!==$mac){throw new \RuntimeException('Audit history continuity failed.');}$sequence=$value['sequence'];$mac=$row['mac'];if($checkpoint!==null&&$checkpoint['sequence']===$sequence){$pin=$mac;}}
        }
        $actual=(int)($this->db->select('SELECT MAX(sequence) AS latest FROM '.$this->rows.' WHERE stream=?',[$stream])[0]['latest']??$head['base']);
        if($actual!==$head['sequence']||$sequence!==$head['sequence']||!hash_equals($head['mac'],$mac)||$checkpoint!==null&&(!is_string($pin)||!hash_equals($checkpoint['mac']??'',$pin))){throw new \RuntimeException('Audit tail/checkpoint integrity failed.');}return $head;
    }
    public function verify(TenantScope $scope,?array $checkpoint=null):array{$scope->requireAbility('audit.read');return $this->locked(fn():array=>$this->verifyLocked($scope->namespace('audit'),$checkpoint));}
    public function checkpoint(TenantScope $scope):array
    {
        $head=$this->verify($scope);$pin=['format'=>1,'stream'=>$head['stream'],'sequence'=>$head['sequence'],'mac'=>$head['mac'],'kid'=>$this->active,'issued'=>$this->now()];$pin['signature']=$this->mac('audit-checkpoint',$pin,$this->active);return $pin;
    }
    public function read(TenantScope $scope,int $after=0,int $limit=100):array
    {
        $scope->requireAbility('audit.read');if($after<0||$limit<1||$limit>500){throw new \InvalidArgumentException('Invalid audit page bounds.');}
        return $this->locked(function()use($scope,$after,$limit):array{$stream=$scope->namespace('audit');$head=$this->verifyLocked($stream);if($after<$head['base']){throw new \RuntimeException('Use the archived audit segment before resuming this cursor.');}return array_map(fn(array $row):array=>$this->decode($row,$stream),$this->db->select('SELECT * FROM '.$this->rows.' WHERE stream=? AND sequence>? ORDER BY sequence LIMIT '.$limit,[$stream,$after]));});
    }
    public function hold(TenantScope $scope,bool $enabled):void
    {
        $scope->requireAbility('audit.retain');$this->locked(function()use($scope,$enabled):void{$head=$this->verifyLocked($scope->namespace('audit'));$head['hold']=$enabled;$this->save($head);});
    }
    /** The caller must durably archive the segment/checkpoint outside this database before confirming. */
    public function prune(TenantScope $scope,array $archivedCheckpoint,bool $archiveConfirmed=false,int $limit=500):array
    {
        $scope->requireAbility('audit.prune');if(!$archiveConfirmed||$limit<1||$limit>500){throw new \LogicException('Archive confirmation and bounded pruning are required.');}
        return $this->locked(function()use($scope,$archivedCheckpoint,$limit):array{
            $stream=$scope->namespace('audit');$head=$this->verifyLocked($stream,$archivedCheckpoint);if($head['hold']){throw new \RuntimeException('Audit retention hold is active.');}
            $rows=$this->db->select('SELECT * FROM '.$this->rows.' WHERE stream=? AND sequence>? ORDER BY sequence LIMIT '.$limit,[$stream,$head['base']]);$count=0;
            foreach($rows as $row){$value=$this->decode($row,$stream);if($value['time']>$this->now()-$this->retentionSeconds||$value['sequence']>$archivedCheckpoint['sequence']){break;}$head['base']=$value['sequence'];$head['base_mac']=$row['mac'];$count++;}
            if($count){$this->db->execute('DELETE FROM '.$this->rows.' WHERE stream=? AND sequence<=?',[$stream,$head['base']]);$this->save($head);}return ['removed'=>$count,'base_sequence'=>$head['base'],'base_mac'=>$head['base_mac']];
        });
    }
}
