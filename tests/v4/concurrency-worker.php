<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\TenantScope;
use Corevia\Persistence\{SqlConnection,SqlOutbox,SqlSyncStore,SqlUsageBudget};
$directory=realpath($argv[2]??'');$storage=realpath(dirname(__DIR__,2).'/storage');
if($directory===false||dirname($directory)!==$storage||!str_starts_with(basename($directory),'v4-concurrency-')){throw new LogicException('Use an isolated concurrency fixture directory.');}
$pdo=new PDO('sqlite:'.$directory.'/test.sqlite');$pdo->exec('PRAGMA busy_timeout=10000');$db=new SqlConnection($pdo);
$scope=new TenantScope('alpha','fixture',fn()=>true);$budget=new SqlUsageBudget($db,100);$sync=new SqlSyncStore($db,str_repeat('s',32));$outbox=new SqlOutbox($db);
if(($argv[1]??'')==='prepare'){
    $budget->migrate();$sync->migrate();$outbox->migrate();
    for($i=0;$i<12;$i++){$outbox->commit('test.delivery',['number'=>$i],fn()=>null,1000);}
    echo "prepared\n";exit;
}
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
echo json_encode(['reserved'=>$reserved,'mutation'=>$mutation,'sent'=>$sent],JSON_THROW_ON_ERROR)."\n";
