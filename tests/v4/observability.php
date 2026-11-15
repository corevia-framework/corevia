<?php
declare(strict_types=1);
namespace CoreviaV4Observability;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Corevia,Deadline,Telemetry,TraceContext,WorkContext};
use Corevia\Http\{HttpClient,OperationsRoutes,OtlpTraceExporter,Request,RequestTelemetry,Response,Router};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Observability contract failed.'); } }
function rejects(callable $work):void { try { $work(); } catch (\Throwable) { return; }throw new \RuntimeException('Unsafe observability operation accepted.'); }
$parent=new TraceContext(str_repeat('a',32),str_repeat('b',16));
check(TraceContext::parse($parent->header())?->traceId===$parent->traceId);
foreach (['00-'.str_repeat('0',32).'-'.str_repeat('b',16).'-01','00-'.str_repeat('a',32).'-'.str_repeat('0',16).'-01','01-'.str_repeat('a',32).'-'.str_repeat('b',16).'-01',"{$parent->header()}\r\nsecret"] as $bad) { check(TraceContext::parse($bad)===null); }
$tick=0;$deadline=new Deadline(10,static function()use(&$tick):int { return $tick; });
rejects(fn()=>WorkContext::run($deadline,function()use($deadline,&$tick):void {
    WorkContext::run(new Deadline(1000),function($context)use($deadline,&$tick):void { check($context->remainingMilliseconds()<=10);$deadline->cancel();$context->check(); });
}));check(WorkContext::current()===null);
$fiber=new \Fiber(fn()=>WorkContext::run(new Deadline(1000),function($context):void { \Fiber::suspend($context);check(WorkContext::current()===$context); }));
$held=$fiber->start();check($held instanceof WorkContext&&WorkContext::current()===null);$fiber->resume();check(WorkContext::current()===null);
$telemetry=new Telemetry('fixture',['http.detail'=>'server','http.stream'=>'internal','sql.read'=>'client'],2);
$value=$telemetry->span('http.detail',fn()=>$telemetry->span('sql.read',fn()=>42),$parent);check($value===42&&WorkContext::current()===null);
rejects(fn()=>$telemetry->span('http.detail',static function():void { throw new \RuntimeException('private-password-prompt-body'); }));
$snapshot=$telemetry->snapshot();check(count($snapshot['spans'])===2&&$snapshot['dropped_spans']===1&&!str_contains(json_encode($snapshot),'private-password-prompt-body'));
check($snapshot['spans'][0]['traceId']===$parent->traceId&&$snapshot['spans'][1]['parentSpanId']===$parent->spanId);
rejects(fn()=>$telemetry->span('/users/private-email',fn()=>null));
check(str_contains($telemetry->prometheus(),'corevia_operations_total{service="fixture",operation="http.detail",outcome="error"} 1'));
$client=new class implements HttpClient {
    public array $sent=[];public string $reply='{}';
    public function stream(string $method,string $url,array $headers=[],string $body=''):iterable { $this->sent[]=json_decode($body,true,32,JSON_THROW_ON_ERROR);yield $this->reply; }
};
$exporter=new OtlpTraceExporter($client,'https://collector.example.test/v1/traces');
check($exporter->export($telemetry)===['exported'=>2,'dropped'=>1]);$packet=$client->sent[0];
check($packet['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['kind']===3&&$packet['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['traceId']===$parent->traceId);
check($exporter->export($telemetry)['exported']===0&&count($client->sent)===1);
$telemetry->span('sql.read',fn()=>true);(new OtlpTraceExporter($client,'https://collector.example.test/v1/traces',metricsEndpoint:'https://collector.example.test/v1/metrics'))->export($telemetry);
$metrics=$client->sent[1]['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];check(count($metrics)===2&&$metrics[0]['sum']['aggregationTemporality']===1&&$metrics[0]['sum']['dataPoints'][0]['asInt']==='1');
$client->reply='{"partialSuccess":{"rejectedSpans":"1"}}';$telemetry->span('sql.read',fn()=>true);rejects(fn()=>$exporter->export($telemetry));
foreach(['{"partialSuccess":{}}','{"partialSuccess":{"rejectedSpans":"0","errorMessage":""}}','{"partialSuccess":{"rejectedDataPoints":0}}']as $reply){
    $client->reply=$reply;$telemetry->span('sql.read',fn()=>true);check($exporter->export($telemetry)['exported']===1);
}
foreach(['[]','null','{"partialSuccess":null}','{"partialSuccess":[]}','{"partialSuccess":{"rejectedSpans":1}}','{"partialSuccess":{"rejectedSpans":false}}','{"partialSuccess":{"rejectedDataPoints":"1"}}','{"partialSuccess":{"errorMessage":"private-collector-warning"}}','{"partialSuccess":{"unknown":0}}',str_repeat('x',65537)]as $reply){
    $client->reply=$reply;$telemetry->span('sql.read',fn()=>true);rejects(fn()=>$exporter->export($telemetry));check($telemetry->snapshot()['spans']===[]);
}

$app=Corevia::create(dirname(__DIR__,2));$app->boot();$router=new Router($app);
try {
    $router->get('/detail/{id}',fn($r)=>new Response(['ok'=>true]))->middleware(new RequestTelemetry($telemetry,'http.detail'));
    $result=$router->dispatch(new Request('GET','/detail/private-identifier',['traceparent'=>$parent->header(),'authorization'=>'secret']));
    check($result->status===200&&TraceContext::parse($result->headers['traceparent'])?->traceId===$parent->traceId);
    check(!str_contains(json_encode($telemetry->snapshot()),'private-identifier')&&!str_contains(json_encode($telemetry->snapshot()),'secret'));
    $streamed=false;$middleware=new RequestTelemetry($telemetry,'http.detail',5000,'http.stream');
    $stream=$middleware->process(new Request('GET','/stream'),static function()use(&$streamed):Response { return new Response(static function()use(&$streamed):void { check(WorkContext::current()!==null);$streamed=true; }); });
    check(WorkContext::current()===null);($stream->body)();check($streamed&&WorkContext::current()===null);
    OperationsRoutes::mount($router,$app,$telemetry,fn($r)=>$r->header('authorization')==='Bearer fixture',['db'=>static function():bool { throw new \RuntimeException('private-connection'); }]);
    check($router->dispatch(new Request('GET','/_corevia/metrics'))->status===404);
    check($router->dispatch(new Request('GET','/_corevia/inspect',['authorization'=>'Bearer fixture']))->status===404);
    $ready=$router->dispatch(new Request('GET','/_corevia/ready',['authorization'=>'Bearer fixture']));check($ready->status===503&&$ready->body===['ready'=>false,'checks'=>['runtime'=>true,'db'=>false]]);
    check(!str_contains(json_encode($ready->body),'private-connection'));
} finally { $app->shutdown(); }
$app=Corevia::create(dirname(__DIR__,2));$app->boot();$router=new Router($app);
try {
    OperationsRoutes::mount($router,$app,$telemetry,fn()=>true,environment:'testing',queries:fn()=>[
        hash('sha256','query')=>['count'=>2,'duration_ms'=>1.5,'binding'=>'private-sql-binding'],
        'SELECT private-data'=>['count'=>3,'duration_ms'=>2.0],
    ],jobs:fn()=>['pending'=>2,'payload'=>'private-job']);
    $inspect=$router->dispatch(new Request('GET','/_corevia/inspect'));check($inspect->status===200&&count($inspect->body['queries'])===1&&$inspect->body['jobs']['pending']===2);
    check(!str_contains(json_encode($inspect->body),'private-'));
} finally { $app->shutdown(); }

$logger=new class implements \Corevia\Core\Logger { public function log(string $level,string $message,array $context=[]):void {} };
$queries=new \Corevia\Persistence\QueryDiagnostics($logger,'fixture',capacity:1);$queries->record('SELECT one',1.0);$queries->record('SELECT two',1.0);check(count($queries->snapshot())===1&&$queries->droppedPatterns()===1);

$anonymousTelemetry=new Telemetry('fixture',['private.failure'=>'internal']);$anonymous=new class('private message') extends \RuntimeException {};rejects(fn()=>$anonymousTelemetry->span('private.failure',static fn()=>throw $anonymous));$anonymousSpan=$anonymousTelemetry->snapshot()['spans'][0];check($anonymousSpan['errorType']==='Throwable'&&!str_contains(json_encode($anonymousSpan),__FILE__));

echo "PASS bounded redacted spans/metrics, nested trace/budget/Fiber cleanup, OTLP envelopes, streaming contexts, private health and production inspector denial\n";
