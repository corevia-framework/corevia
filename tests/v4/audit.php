<?php
declare(strict_types=1);
namespace CoreviaV4Audit;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\TenantScope;
use Corevia\Persistence\{AuditTrail,SqlConnection};
use Corevia\Testing\Clock;
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Audit contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Unsafe audit operation accepted.');}
$clock=new Clock(1700000000);$db=new SqlConnection(new \PDO('sqlite::memory:'));$keys=['first'=>str_repeat('k',32),'next'=>str_repeat('n',32)];
$schema=['record.changed'=>['record_id'=>'identifier','revision'=>'integer','deleted'=>'boolean']];$audit=new AuditTrail($db,$keys,'first',$schema,60,clock:$clock);$audit->migrate();
$valid=true;$admin=new TenantScope('alpha','alice',static function()use(&$valid):bool{return $valid;},['audit.append','audit.read','audit.retain','audit.prune']);
$read=new TenantScope('alpha','reader',fn()=>true,['audit.read']);$other=new TenantScope('beta','bob',fn()=>true,['audit.read']);
$first=$audit->append($admin,'record.changed',['record_id'=>'one','revision'=>1,'deleted'=>false],'event-one');$oldHead=$db->select('SELECT payload FROM cv_audit_heads')[0]['payload'];
check($audit->append($admin,'record.changed',['record_id'=>'one','revision'=>1,'deleted'=>false],'event-one')===$first);
rejects(fn()=>$audit->append($admin,'record.changed',['email'=>'private@example.test']));rejects(fn()=>$audit->append($admin,'record.changed',['record_id'=>'private content with whitespace']));
rejects(fn()=>$audit->append($admin,'record.changed',['revision'=>2],'event-one'));rejects(fn()=>$audit->append($read,'record.changed',[]));
$rotated=new AuditTrail($db,$keys,'next',$schema,60,clock:$clock);$second=$rotated->append($admin,'record.changed',['record_id'=>'one','revision'=>2],'event-two');
$pin=$rotated->checkpoint($read);check($pin['sequence']===2&&$rotated->verify($read,$pin)['sequence']===2);check(count($audit->read($read))===2);check($audit->read($other)===[]);
rejects(fn()=>$rotated->verify($other,$pin));rejects(fn()=>(new AuditTrail($db,['next'=>$keys['next']],'next',$schema,60,clock:$clock))->verify($read));
// Ordinary application writes and the audit append roll back together on the same SQL connection.
$db->execute('CREATE TABLE app_records (id INTEGER PRIMARY KEY)');
rejects(fn()=>$db->transaction(static function()use($db,$rotated,$admin):void{$db->table('app_records')->insert(['id'=>1]);$rotated->append($admin,'record.changed',['revision'=>3]);throw new \RuntimeException('abort');}));
check($db->table('app_records')->count()===0&&$rotated->verify($read)['sequence']===2);
foreach(['payload','tail','head'] as $attack){$db->pdo()->beginTransaction();try{
    if($attack==='payload'){$db->execute('UPDATE cv_audit_events SET payload=? WHERE sequence=1',['{}']);}
    if($attack==='tail'){$db->execute('DELETE FROM cv_audit_events WHERE sequence=2');$db->execute('UPDATE cv_audit_heads SET payload=?',[$oldHead]);}
    if($attack==='head'){$db->execute('UPDATE cv_audit_heads SET payload=?',[$oldHead]);}
    rejects(fn()=>$rotated->verify($read,$pin));
}finally{$db->pdo()->rollBack();}}
check($rotated->prune($admin,$pin,true)['removed']===0);$clock->advance(61);$rotated->hold($admin,true);rejects(fn()=>$rotated->prune($admin,$pin,true));$rotated->hold($admin,false);
rejects(fn()=>$rotated->prune($admin,$pin));check($rotated->prune($admin,$pin,true,1)['removed']===1);check(count($rotated->read($read,1))===1);rejects(fn()=>$rotated->read($read,0));
check($rotated->prune($admin,$pin,true)['removed']===1);check($rotated->verify($read,$pin)['base']===2);$rotated->append($admin,'record.changed',['revision'=>3]);check($rotated->verify($read,$pin)['sequence']===3);
$valid=false;rejects(fn()=>$rotated->append($admin,'record.changed',[]));rejects(fn()=>$rotated->prune($admin,$pin,true));
echo "PASS audit capabilities, tenant isolation, redaction schemas, HMAC chain/checkpoints, key rotation, atomic rollback, retention and legal holds\n";
