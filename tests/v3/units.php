<?php
declare(strict_types=1);
namespace CoreviaV3Units;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Action,Corevia,Disposable,ExecutionContext,ExecutionScope,Flow,JobQueue,Logger,Module,ModuleContext,State};
use Corevia\Http\{Request,Router};
use Corevia\Protocol\Protocol;
use Corevia\Realtime\Server;
function check(bool $value):void{if(!$value){throw new \RuntimeException('Unit-of-work assertion failed.');}}
final class Quiet implements Logger{public function log(string $level,string $message,array $context=[]):void{}}
final class Resource implements Disposable{
    public static array $created=[];public bool $closed=false;
    public function __construct(){self::$created[]=$this;}
    public function dispose():void{$this->closed=true;}
}
final class Work implements Action{
    public function __construct(private Resource $resource,private ExecutionScope $scope){}
    public function handle(State $state,ExecutionContext $context):State{
        check(!$this->resource->closed);$state->data['scope']=$this->scope->id;$state->data['kind']=$this->scope->kind;
        $state->data['resources'][]=spl_object_id($this->resource);return $state;
    }
}
final class Controller{
    public function __construct(private Corevia $app,private Resource $resource,private ExecutionScope $scope){}
    public function show():array{$run=$this->app->run('units',[]);check($run->run['state']['scope']===$this->scope->id);return $run->run['state'];}
    public function fail():array{throw new \RuntimeException('private failure');}
}
$app=Corevia::create(__DIR__,logger:new Quiet());$app->container->scoped(Resource::class,Resource::class);
$app->compose([new class implements Module{
    public function name():string{return 'units';}public function dependencies():array{return [];}
    public function register(ModuleContext $context):void{$context->actions(['work'=>Work::class]);$context->flows([Flow::define('units')->step('work')->step('work')]);}
}]);$app->boot();
$directory=sys_get_temp_dir().'/corevia-v3-units-'.bin2hex(random_bytes(6));
try{
    $router=new Router($app);$router->get('/unit',[Controller::class,'show']);$router->get('/failure',[Controller::class,'fail']);
    $one=$router->dispatch(new Request('GET','/unit'));$two=$router->dispatch(new Request('GET','/unit'));
    check($one->status===200 && $one->body['kind']==='http');check($one->body['scope']!==$two->body['scope']);
    check(count(array_unique($one->body['resources']))===1);check(count(Resource::$created)===2);
    check($router->dispatch(new Request('GET','/failure'))->status===500);
    $direct=$app->run('units',[]);check($direct->run['state']['kind']==='flow');
    $queue=new JobQueue($directory);$queue->dispatch('units',[]);$queue->dispatch('units',[]);
    check($queue->work($app));check($queue->work($app));check(!$queue->work($app));$jobScopes=[];
    foreach(glob($directory.'/*.json') as $file){$job=json_decode(file_get_contents($file),true);check($job['status']==='completed');$state=$app->store->get($job['run_id'])['state'];check($state['kind']==='job');$jobScopes[]=$state['scope'];}
    check($jobScopes[0]!==$jobScopes[1]);
    $seen=[];$server=new Server($app->logger,str_repeat('test-only-',4),authorize:function()use($app,&$seen):bool{
        $seen[]=$app->container->get(ExecutionScope::class)->id;check($app->scopes->current()->kind==='websocket');$app->container->get(Resource::class);return true;
    },scopes:$app->scopes);
    // Exercise two decoded messages on one connection; live socket transport has its own integration suite.
    $clients=new \ReflectionProperty($server,'clients');$clients->setValue($server,[1=>['version'=>null,'connection_id'=>'test-connection','out'=>'','topics'=>[]]]);
    $message=new \ReflectionMethod($server,'message');
    foreach([1,2] as $index){$message->invoke($server,1,Protocol::request('subscribe','private-units',['token'=>'test'],id:'message-'.$index));}
    check($seen[0]!==$seen[1]);check($app->scopes->activeCount()===0);
    foreach(Resource::$created as $resource){check($resource->closed);}
    echo "PASS automatic HTTP/controller, direct flow, queued job and websocket message isolation/cleanup\n";
}finally{$app->shutdown();foreach(glob($directory.'/*')?:[] as $file){unlink($file);}if(is_dir($directory)){rmdir($directory);}}
