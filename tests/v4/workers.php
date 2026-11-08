<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\WorkerLoop;
use Corevia\Core\{Deadline,WorkContext,JobWorker,ScopeManager};
use Corevia\Persistence\{SqlConnection,SqlCoordinator};
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Worker contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid worker bounds accepted.');}
foreach([0,1001]as $max){rejects(fn()=>WorkerLoop::run(fn()=>[], $max));}
foreach([0,3600001]as $budget){rejects(fn()=>WorkerLoop::run(fn()=>[],1,$budget));}
check(WorkerLoop::run(fn()=>['status'=>'empty'])===['processed'=>[],'stopped'=>'empty']);
$calls=0;$report=WorkerLoop::run(function()use(&$calls):array{$calls++;usleep(20000);return ['status'=>'completed'];},10,1);
check($calls===1&&count($report['processed'])===1&&$report['stopped']==='budget'&&WorkContext::current()===null);
$cancel=new Deadline(1000);$cancel->cancel();$calls=0;
try{WorkContext::run($cancel,function()use(&$calls):void{WorkerLoop::run(function()use(&$calls):array{$calls++;return ['status'=>'completed'];});});}catch(RuntimeException){}
check($calls===0&&WorkContext::current()===null);
$db=new SqlConnection(new PDO('sqlite::memory:'));$queue=new SqlCoordinator($db,'cv_soak',1);$queue->migrate();$scopes=new ScopeManager();$closed=0;$seen=[];
$handler=function(array $input,$claim,$scope)use(&$closed,&$seen,$scopes):array{
    check($scopes->activeCount()===1&&$scopes->current()===$scope&&WorkContext::current()!==null);
    $object=$scope->remember('buffer',static fn():object=>(object)['bytes'=>str_repeat('x',32768)]);
    $seen[]=WeakReference::create($object);
    $scope->onClose(function()use(&$closed):void{$closed++;});
    if($input['fail']){throw new RuntimeException('intentional failure');}
    return ['accepted'=>true];
};
// Several batches in one process exercise release of real queued job scopes.
// This bounded fixture is not a sustained production throughput benchmark.
$worker=new JobWorker($queue,$scopes,['soak'=>$handler]);$now=$queue->clock();
for($i=0;$i<1000;$i++){$queue->dispatch('soak',['fail'=>$i%25===0],true,$now);}
$start=hrtime(true);$before=memory_get_usage(true);$completed=0;$failed=0;
for($batch=0;$batch<10;$batch++){
    $report=WorkerLoop::run(fn()=>$worker->once(),100,30000);
    check(count($report['processed'])===100&&$report['stopped']==='maximum');
    foreach($report['processed']as $result){if($result['status']==='completed'){$completed++;}elseif($result['status']==='failed'){$failed++;}else{throw new RuntimeException('Unexpected worker status.');}}
    check($scopes->activeCount()===0&&$scopes->current()===null&&WorkContext::current()===null);
}
gc_collect_cycles();foreach($seen as $reference){check($reference->get()===null);}
check($closed===1000&&$completed===960&&$failed===40);
// Cooperative timeout fails the claim, releases its scope and stops before another claim.
$slow=new JobWorker($queue,$scopes,['slow'=>function($input,$claim,$scope)use(&$closed):array{$scope->onClose(function()use(&$closed):void{$closed++;});usleep(20000);WorkContext::current()->check();return [];}]);
$queue->dispatch('slow',[],true,$queue->clock());
$report=WorkerLoop::run(fn()=>$slow->once(),10,1);
check($report['stopped']==='budget'&&$report['processed'][0]['status']==='failed'&&$closed===1001&&$scopes->activeCount()===0&&WorkContext::current()===null);
echo json_encode(['passed'=>true,'jobs'=>1000,'completed'=>$completed,'injected_failures'=>$failed,'elapsed_ms'=>round((hrtime(true)-$start)/1e6),'allocated_growth_bytes'=>memory_get_usage(true)-$before],JSON_THROW_ON_ERROR)."\n";
