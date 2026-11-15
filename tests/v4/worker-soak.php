<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\WorkerLoop;
use Corevia\Core\{JobWorker,ScopeManager,WorkContext};
use Corevia\Persistence\{SqlConnection,SqlCoordinator};
$seconds=filter_var($argv[1]??60,FILTER_VALIDATE_INT);
if($seconds===false||$seconds<10||$seconds>1800){throw new InvalidArgumentException('Choose 10..1800 soak seconds.');}
$db=new SqlConnection(new PDO('sqlite::memory:'));$queue=new SqlCoordinator($db,'cv_soak',1);$queue->migrate();$scopes=new ScopeManager();$closed=0;$refs=[];
$worker=new JobWorker($queue,$scopes,['soak'=>function($input,$claim,$scope)use(&$closed,&$refs):array{
    $object=$scope->remember('buffer',static fn():object=>(object)['bytes'=>str_repeat('x',65536)]);$refs[]=WeakReference::create($object);
    $scope->onClose(function()use(&$closed):void{$closed++;});
    if($input['fail']){throw new RuntimeException('Injected soak failure.');}
    WorkContext::current()->check();return ['accepted'=>true];
}]);
$start=hrtime(true);$jobs=0;$failed=0;$baseline=null;$maximumGrowth=0;$batches=0;$baselineRss=null;$maximumRssGrowth=null;
do{
    $refs=[];$now=$queue->clock();
    for($index=0;$index<25;$index++){$queue->dispatch('soak',['fail'=>$index===0],true,$now);}
    $report=WorkerLoop::run(fn()=>$worker->once(),25,10000);
    if(count($report['processed'])!==25||$scopes->activeCount()!==0||WorkContext::current()!==null){throw new RuntimeException('Soak lifecycle failed.');}
    foreach($report['processed']as $result){if($result['status']==='failed'){$failed++;}elseif($result['status']!=='completed'){throw new RuntimeException('Unexpected soak status.');}}
    gc_collect_cycles();foreach($refs as $reference){if($reference->get()!==null){throw new RuntimeException('Scope object retained.');}}
    // Test-only table recycling keeps completed fixture data out of the leak measurement.
    $db->execute("DELETE FROM cv_soak_jobs WHERE status IN ('done','dead')");$jobs+=25;$batches++;
    if($jobs>=1000){$baseline??=memory_get_usage(true);$maximumGrowth=max($maximumGrowth,memory_get_usage(true)-$baseline);}
    if(PHP_OS_FAMILY==='Linux'&&is_readable('/proc/self/status')&&preg_match('/^VmRSS:\s+(\d+)\s+kB/m',file_get_contents('/proc/self/status'),$rss)&&$jobs>=1000){$bytes=(int)$rss[1]*1024;$baselineRss??=$bytes;$maximumRssGrowth=max($maximumRssGrowth??0,$bytes-$baselineRss);}
    if(($maximumRssGrowth??0)>32*1024*1024){throw new RuntimeException('Unexpected process RSS growth.');}
    if($maximumGrowth>8*1024*1024){throw new RuntimeException('Unexpected retained allocation growth.');}
    usleep(10000);
}while((hrtime(true)-$start)/1e9<$seconds);
if($jobs<1000||$closed!==$jobs||$failed!==$batches){throw new RuntimeException('Missing soak cleanup/failure results.');}
echo json_encode(['passed'=>true,'duration_seconds'=>(hrtime(true)-$start)/1e9,'jobs'=>$jobs,'injected_failures'=>$failed,'cleanups'=>$closed,'warmup_jobs'=>1000,'max_allocated_growth_after_warmup'=>$maximumGrowth,'max_linux_rss_growth_after_warmup'=>$maximumRssGrowth,'scope'=>'single-process in-memory SQLite job lifecycle; no provider or throughput comparison'],JSON_THROW_ON_ERROR)."\n";
