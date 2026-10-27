<?php
declare(strict_types=1);
namespace CoreviaWorkflowExample;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Action,ActionResult,Corevia,ExecutionContext,Flow,JsonRunStore,Module,ModuleContext,State};

final class Reserve implements Action {
    public function handle(State $s,ExecutionContext $c):State { $s->data['reservation']='reserved';return $s; }
}
final class Release implements Action {
    public function handle(State $s,ExecutionContext $c):State { $s->data['reservation']='released';return $s; }
}
final class Deliver implements Action {
    public function handle(State $s,ExecutionContext $c):State|ActionResult {
        if ($s->data['simulate_failure']??false) {return ActionResult::failure('DELIVERY_FAILED','Simulated failure.');}
        $s->data['delivered']=true;return $s;
    }
}
final class Demo implements Module {
    public function name():string{return 'demo';}public function dependencies():array{return [];}
    public function register(ModuleContext $c):void {
        $c->actions(['reserve'=>Reserve::class,'release'=>Release::class,'deliver'=>Deliver::class]);
        $c->flows([Flow::define('approved-delivery')->step('reserve')->outputs(['reservation'])->compensate('release')->approval('owner')->step('deliver')]);
    }
}
$store=new JsonRunStore(dirname(__DIR__,2).'/storage/example-workflows.json');
$app=Corevia::create(__DIR__,store:$store);$app->modules([new Demo()]);
try {
    $app->boot();
    $result=($argv[1]??'start')==='resume' ? $app->resume($argv[2]??'') : $app->run('approved-delivery',['simulate_failure'=>in_array('--fail',$argv,true)]);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{$app->shutdown();}
