<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\TenantScope;
use Corevia\Persistence\{SqlConnection,SqlCoordinator,SqlOutbox,SqlResilience,SqlSyncStore,SqlUsageBudget};
$directory=realpath($argv[2]??'');$storage=realpath(dirname(__DIR__,2).'/storage');
if($directory===false||dirname($directory)!==$storage||!str_starts_with(basename($directory),'v4-concurrency-')){throw new LogicException('Use an isolated concurrency fixture directory.');}
$pdo=new PDO('sqlite:'.$directory.'/test.sqlite');$pdo->exec('PRAGMA busy_timeout=10000');$db=new SqlConnection($pdo);
$scope=new TenantScope('alpha','fixture',fn()=>true);$budget=new SqlUsageBudget($db,100);$sync=new SqlSyncStore($db,str_repeat('s',32));$outbox=new SqlOutbox($db);$queue=new SqlCoordinator($db);
if(($argv[1]??'')==='prepare'){
    // Provision only this private fixture for the documented local multi-worker SQLite mode.
    if(strtolower((string)$pdo->query('PRAGMA journal_mode=WAL')->fetchColumn())!=='wal'){throw new RuntimeException('Multi-worker fixture requires SQLite WAL on local disk.');}
    $budget->migrate();$sync->migrate();$outbox->migrate();$queue->migrate();$resilience=new SqlResilience($db,'shared',1,10,30,10,60,clock:fn()=>1000);$resilience->migrate();$resilience->fail($resilience->enter());
    for($i=0;$i<12;$i++){$outbox->commit('test.delivery',['number'=>$i],fn()=>null,1000);}
    echo "prepared\n";exit;
}
$dispatch=$queue->dispatchOnce('one-occurrence','scheduled.work',['fixture'=>true],true,1000);
$queueClaim=$queue->claim(1000);$claimed=$queueClaim!==null?1:0;
$lease=$queue->acquire('overlap',1000);$overlap=$lease!==null?1:0;
$resilience=new SqlResilience($db,'shared',1,10,30,10,60,clock:fn()=>1010);
$probe=0;try{$resilience->enter();$probe=1;}catch(RuntimeException $error){if($error->getMessage()!=='CIRCUIT_OPEN'){throw $error;}}
$retries=0;for($i=0;$i<20;$i++){if(!$resilience->takeRetry()){break;}$retries++;}
$reserved=0;
for($i=0;$i<20;$i++){
    try{$budget->reserve($scope,10,1000);$reserved++;}
    catch(RuntimeException $error){if($error->getMessage()!=='TENANT_BUDGET_EXHAUSTED'){throw $error;}break;}
}
$mutation=0;try{$sync->mutate($scope,'same',0,['writer'=>$argv[3]??'unknown']);$mutation=1;}
catch(RuntimeException $error){if($error->getMessage()!=='SYNC_REVISION_CONFLICT'){throw $error;}}
$sent=0;
while($outbox->relay(static function($event)use($directory,&$sent):void{
    $file=fopen($directory.'/deliveries.txt','ab');if($file===false||!flock($file,LOCK_EX)){throw new RuntimeException('Fixture log unavailable.');}
    try{if(fwrite($file,$event['event_id']."\n")!==33){throw new RuntimeException('Fixture write failed.');}$sent++;}finally{flock($file,LOCK_UN);fclose($file);}
},1000)){}
echo json_encode(['reserved'=>$reserved,'mutation'=>$mutation,'sent'=>$sent,'dispatch'=>$dispatch,'claimed'=>$claimed,'overlap'=>$overlap,'probe'=>$probe,'retries'=>$retries],JSON_THROW_ON_ERROR)."\n";
