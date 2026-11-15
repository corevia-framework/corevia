<?php
declare(strict_types=1);
// Same harness can load baseline or current code. No app config, sockets or persistent state.
require $argv[1];
use Corevia\Core\{Corevia,Logger};
use Corevia\Http\{Request,Router};
$quiet=new class implements Logger{public function log(string $level,string $message,array $context=[]):void{}};
$measurements=[];
for($sample=0;$sample<200;$sample++){
    $start=hrtime(true);$app=Corevia::create(__DIR__,logger:$quiet);$router=new Router($app);
    $router->get('/up',static fn():array=>['status'=>'ok']);$app->compose([]);$app->boot();
    $response=$router->dispatch(new Request('GET','/up'));$app->shutdown();
    if($response->status!==200){throw new RuntimeException('Benchmark fixture failed.');}
    if($sample>=20){$measurements[]=(hrtime(true)-$start)/1000000;}
}
sort($measurements,SORT_NUMERIC);
$percentile=static fn(float $p):float=>$measurements[(int)ceil(count($measurements)*$p)-1];
echo json_encode(['fixture'=>'in_process_boot_dispatch_shutdown','samples'=>count($measurements),
    'p50_ms'=>$percentile(.5),'p95_ms'=>$percentile(.95),'p99_ms'=>$percentile(.99),
    'peak_memory_bytes'=>memory_get_peak_usage(true),'php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,
    'limits'=>['Not HTTP throughput; PHP process startup and external I/O are excluded.',
        'No Laravel/CodeIgniter comparison or superiority claim.']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
