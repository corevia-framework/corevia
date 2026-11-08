<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Http\{HttpClient,RemoteCancellation,Router,Request};
use Corevia\Protocol\{Protocol,RemoteAction};
use Corevia\Core\Corevia;
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Cancellation contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(Throwable){return;}throw new RuntimeException('Unsafe cancellation accepted.');}
$client=new class implements HttpClient {
    public string $reply='{"accepted":true}';public array $packets=[];
    public function stream(string $method,string $url,array $headers=[],string $body=''):iterable{$this->packets[]=json_decode($body,true,8,JSON_THROW_ON_ERROR);yield $this->reply;}
};
$control=new RemoteCancellation($client,'https://worker.example.test/cvp/cancel',['Authorization'=>'Bearer fixture']);
$control->request('call-id',str_repeat('a',64));check($client->packets[0]===['request_id'=>'call-id','capability'=>str_repeat('a',64)]);
foreach(['http://worker.example.test/cvp/cancel','https://user:password@worker.example.test/cancel','https://worker.example.test/cancel?secret=value']as $endpoint){rejects(fn()=>new RemoteCancellation($client,$endpoint));}
rejects(fn()=>$control->request('call-id','invalid'));rejects(fn()=>$control->request("call\nid",str_repeat('a',64)));
foreach(['{"accepted":false}','{}','{"accepted":true,"unknown":true}',str_repeat('a',1025)]as $reply){$client->reply=$reply;rejects(fn()=>$control->request('call-id',str_repeat('a',64)));}
rejects(fn()=>(new RemoteAction('http://127.0.0.1:1/cvp'))->call('work',[],requestId:'id',cancelCapability:str_repeat('a',64)));
rejects(fn()=>(new RemoteAction('http://127.0.0.1:1/cvp',version:Protocol::V2))->call('work',[],cancelCapability:str_repeat('a',64)));
$wire=Protocol::request('action.call','example',[],id:'id',version:Protocol::V2);$wire['meta']['cancel_capability']=str_repeat('a',64);(new Protocol())->validate($wire);
foreach([null,false,'invalid']as $bad){$copy=$wire;$copy['meta']['cancel_capability']=$bad;rejects(fn()=>(new Protocol())->validate($copy));}
$app=Corevia::create(dirname(__DIR__,2));$app->boot();$router=new Router($app);
try{$router->actionGateway(['example'],fn()=>true);$response=$router->dispatch(new Request('POST','/cvp',['content-type'=>'application/json'],json_encode($wire,JSON_THROW_ON_ERROR)));check($response->status===422&&$response->body['error']['code']==='CANCELLATION_UNAVAILABLE');}finally{$app->shutdown();}
echo "PASS cancellation endpoint/handle bounds, explicit capability validation and unsupported PHP gateway rejection\n";
