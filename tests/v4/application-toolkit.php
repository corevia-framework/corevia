<?php
declare(strict_types=1);
namespace CoreviaV4Toolkit;
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Core\{Corevia,ScopeManager,Translator};
use Corevia\Http\{ApiBoundary,ApiResponse,LocalDisk,OpenApi,RequestValidator,Router};
use Corevia\Ai\AiClient;
use Corevia\Auth\AccountMessage;
use Corevia\Testing\{Clock,Factory,FakeAi,FakeMail,FakeQueue,TestClient};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Toolkit contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected toolkit rejection.');}
$clock=new Clock(time());$queue=new FakeQueue($clock);$id=$queue->dispatch('task',['id'=>1],true,$clock());$claim=$queue->claim($clock(),10);check($claim->id===$id);$clock->advance(11);check(!$queue->complete($claim,[],$clock()));$replacement=$queue->claim($clock(),10);check($replacement->token()!==$claim->token());check($queue->complete($replacement,[],$clock()));
$mail=new FakeMail();$mail->deliver(new AccountMessage('fixture@example.test','verify',time()+600,'https://example.invalid/#synthetic'));$mail->assertSent('verify');
$provider=new FakeAi(['hel','lo']);$client=new AiClient($provider,new ScopeManager());check($client->complete([['role'=>'user','content'=>'fixture']])==='hello');check(count($provider->calls())===1);
check(Factory::records(2,fn($i)=>['id'=>$i])===[['id'=>0],['id'=>1]]);
$app=Corevia::create(__DIR__);$router=new Router($app,['http://127.0.0.1:8081']);$app->container->set(Router::class,$router);
$router->get('/api/v1/items',fn()=>ApiResponse::data([['id'=>1]]))->middleware(new ApiBoundary())->describe('listItems',[],['type'=>'object'],['items.read']);
$router->post('/api/v1/items',fn()=>throw new \InvalidArgumentException('not exposed'))->middleware(new ApiBoundary())->describe('createItem',['type'=>'object','properties'=>['title'=>['type'=>'string']]]);
$app->boot();try{
    $http=new TestClient($router);$http->request('GET','/api/v1/items?page=1')->status(200)->field('data.0.id',1);
    $http->request('POST','/api/v1/items',[])->status(422)->field('error.code','INVALID_INPUT');
    $schema=OpenApi::generate($router,'Fixture','1.0.0');check($schema['openapi']==='3.1.0');check(isset(((array)$schema['paths'])['/api/v1/items']['get']['security']));
}finally{$app->shutdown();}
$validator=new RequestValidator();$errors=$validator->validate(['items'=>[['name'=>'A'],['name'=>2]]],['items.*.name'=>['required','string']]);check(isset($errors['items.1.name'])&&!isset($errors['items.0.name']));
$translator=new Translator(['en'=>['hello'=>'Hello :name'],'ur'=>['hello'=>'Salam :name']],'ur');check($translator->direction()==='rtl'&&$translator->text('hello',['name'=>'Ada'])==='Salam Ada');
$directory=sys_get_temp_dir().'/corevia-v4-disk-'.bin2hex(random_bytes(6));mkdir($directory);mkdir($directory.'/public');
try{$disk=new LocalDisk($directory.'/private',$directory.'/public',32);$disk->put('tenant/file.txt','safe');check($disk->get('tenant/file.txt')==='safe');rejects(fn()=>$disk->put('tenant/file.txt','overwrite'));rejects(fn()=>$disk->get('../outside'));rejects(fn()=>$disk->put('large',str_repeat('a',33)));rejects(fn()=>new LocalDisk($directory.'/public/files',$directory.'/public'));$disk->delete('tenant/file.txt');check(!$disk->exists('tenant/file.txt'));}finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-disk-');}
$previous=getenv('APP_ENV');putenv('APP_ENV=production');try{rejects(fn()=>new FakeAi());rejects(fn()=>new TestClient(new Router(Corevia::create(__DIR__))));}finally{putenv($previous===false?'APP_ENV':'APP_ENV='.$previous);}
echo "PASS application HTTP assertions, fake clock/jobs/mail/AI, nested validation, OpenAPI, localization and private files\n";
