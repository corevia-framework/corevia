<?php
declare(strict_types=1);
/** Local, bounded demonstration: no outbound calls or application data changes. */
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{CronExpression,ScheduleEntry,ScopeManager};
use Corevia\Persistence\{SqlConnection,SqlCoordinator,SqlScheduler};

$directory=dirname(__DIR__,2).'/storage/scheduler-example';
if (!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)) { throw new RuntimeException('Cannot create private example storage.'); }
$db=SqlConnection::connect(['driver'=>'sqlite','database'=>$directory.'/queue.sqlite','options'=>[PDO::ATTR_TIMEOUT=>5]]);
$queue=new SqlCoordinator($db,'cv_example_schedule');
$scheduler=new SqlScheduler($queue,new ScopeManager(),'example',[
    new ScheduleEntry('minute-report',new CronExpression('* * * * *'),'Asia/Karachi','report',['message'=>'Hello from a scheduled job']),
]);
$action=$argv[1]??'help';
$result=match($action) {
    'migrate'=>(static function()use($queue):array { $queue->migrate();return ['status'=>'migrated']; })(),
    'tick'=>$scheduler->tick(),
    'work'=>$scheduler->workOnce(['report'=>static function($input,$claim,$deadline,$scope,$fence):array {
        $deadline->check();
        // Pass $claim->idempotencyKey() to downstream writes; use the deadline for adapter timeouts.
        return ['message'=>$input['message'],'effect_key'=>$claim->idempotencyKey(),'fence'=>$fence];
    }]),
    'inspect'=>$queue->inspect(),
    default=>['usage'=>'php examples/scheduler/run.php migrate|tick|work|inspect'],
};
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
