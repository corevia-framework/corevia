<?php
declare(strict_types=1);
namespace CoreviaV3Contracts;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Action,ActionResult,Corevia,ExecutionContext,Module,ModuleContext,State};
use Corevia\Http\{Request,Router};
use Corevia\Protocol\{ContractGenerator,ContractManifest,ContractViolation,Protocol};
function check(bool $value):void{if(!$value){throw new \RuntimeException('Assertion failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected rejection.');}
$root=dirname(__DIR__,2);$manifest=ContractManifest::load($root.'/contracts/example.json');$generator=new ContractGenerator($manifest);$generator->write($root.'/contracts/generated',true);
$fixtures=json_decode(file_get_contents($root.'/contracts/fixtures.json'),false,64,JSON_THROW_ON_ERROR);
foreach($fixtures as $fixture){$valid=true;try{match($fixture->kind){'input'=>$manifest->assertInput($fixture->name,$fixture->value),'output'=>$manifest->assertOutput($fixture->name,$fixture->value),'event'=>$manifest->assertEvent($fixture->name,$fixture->value)};}catch(ContractViolation){$valid=false;}check($valid===$fixture->valid);}
check($manifest->requirements('demo.greet')[0]['scopes']===['greet:read']);$manifest->assertError('demo.greet','UNAVAILABLE',true);rejects(fn()=>$manifest->assertError('demo.greet','UNAVAILABLE',false));
$bad=$manifest->data();$bad['$defs']['GreetingInput']['pattern']='.*';rejects(fn()=>ContractManifest::fromArray($bad));
$bad=$manifest->data();$bad['$defs']['GreetingInput']=['$ref'=>'#/$defs/GreetingInput'];rejects(fn()=>ContractManifest::fromArray($bad));
$bad=$manifest->data();$bad['actions']['demo.greet']['auth']=[['scheme'=>'missing']];rejects(fn()=>ContractManifest::fromArray($bad));
$factory=require $root.'/contracts/generated/php/client.php';$client=$factory(static fn(string $name,array $payload):array=>['message'=>'Hello '.$payload['name']]);check($client->action('demo.greet',['name'=>'Ada'])['message']==='Hello Ada');
final class Greet implements Action{public function handle(State $state,ExecutionContext $context):ActionResult{return ActionResult::success(['message'=>'Hello '.$state->data['name']]);}}
$app=Corevia::create($root);$app->compose([new class implements Module{public function name():string{return 'demo';}public function dependencies():array{return [];}public function register(ModuleContext $context):void{$context->actions(['demo.greet'=>Greet::class]);}}]);$app->boot();
try{
    $router=new Router($app);$router->actionGateway(['demo.greet'],static fn(Request $request,string $action,array $requirements):bool=>$request->header('authorization')==='Bearer test-only' && $requirements[0]['scheme']==='session',$manifest);
    $call=static fn(array $input,string $auth='Bearer test-only')=>$router->dispatch(new Request('POST','/cvp',['authorization'=>$auth],json_encode(Protocol::request('action.call','demo.greet',$input,version:Protocol::V2),JSON_THROW_ON_ERROR)));
    check($call(['name'=>'Ada'])->body['result']['message']==='Hello Ada');check($call(['name'=>false])->status===400);check($call(['name'=>'Ada'],'')->status===403);
}finally{$app->shutdown();}
$temporary=sys_get_temp_dir().'/corevia-contracts-'.bin2hex(random_bytes(6));
try{$generator->write($temporary);file_put_contents($temporary.'/js/client.js','hand edited');rejects(fn()=>$generator->write($temporary));rejects(fn()=>$generator->write($temporary,true));}
finally{$walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($walk as $file){if($file->isDir()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($temporary);}
echo 'PASS '.count($fixtures)." shared application fixtures, generated PHP client, gateway validation and protected generation\n";
