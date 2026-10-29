<?php
declare(strict_types=1);
namespace CoreviaProfileExample;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Action,Corevia,ExecutionContext,Flow,Logger,Module,ModuleContext,ModuleDefinition,State};
final class Quiet implements Logger{public function log(string $level,string $message,array $context=[]):void{}}
final class Greet implements Action{
    public function handle(State $state,ExecutionContext $context):State{$state->data['message']='Hello '.($state->data['name']??'developer');return $state;}
}
final class GreetingModule implements Module{
    public function name():string{return 'greeting';}public function dependencies():array{return [];}
    public function register(ModuleContext $context):void{$context->actions(['demo.greet'=>Greet::class]);}
}
final class QueueModule implements Module{
    public function name():string{return 'queue';}public function dependencies():array{return ['greeting'];}
    public function register(ModuleContext $context):void{$context->flows([Flow::define('demo.hello')->step('demo.greet')]);}
}
$app=Corevia::create(dirname(__DIR__,2),logger:new Quiet());
$app->profile($argv[1]??'web',[
    new ModuleDefinition('greeting',GreetingModule::class,['web','api','ai']),
    new ModuleDefinition('queue',QueueModule::class,['worker'],['greeting']),
]);
try{
    $app->boot();$output=['profile'=>$app->runtimeProfile(),'boot_order'=>$app->registry->plan()->order];
    if(in_array('queue',$app->runtimeProfile()->selected,true)){$output['run']=$app->run('demo.hello',['name'=>'Corevia'])->run['state'];}
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}finally{$app->shutdown();}
