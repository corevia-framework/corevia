<?php
declare(strict_types=1);
$source=$argv[1]??dirname(__DIR__,2);
require $source.'/autoload.php';
use Corevia\Core\{Telemetry,TraceContext,WorkContext};
use Corevia\Http\{NativeHttpClient,OtlpTraceExporter};
$endpoint=$argv[2]??'';$mode=$argv[3]??'export';
if(!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$endpoint)){throw new InvalidArgumentException('Use a private loopback fixture endpoint.');}
$telemetry=new Telemetry('corevia-collector-fixture',['fixture.request'=>'server','fixture.query'=>'client','fixture.failure'=>'internal']);
$parent=new TraceContext(str_repeat('a',32),str_repeat('b',16));
$telemetry->span('fixture.request',fn()=>$telemetry->span('fixture.query',fn()=>42),$parent);
try{$telemetry->span('fixture.failure',static function():void{throw new RuntimeException('PRIVATE_TELEMETRY_SENTINEL');},$parent);}catch(RuntimeException){}
$exporter=new OtlpTraceExporter(new NativeHttpClient(allowLoopback:true,timeout:1),$endpoint.'/v1/traces',metricsEndpoint:$endpoint.'/v1/metrics');
$start=hrtime(true);$failed=false;
try{$result=$exporter->export($telemetry);}catch(RuntimeException $error){$failed=true;$result=null;if($mode!=='outage'){throw new RuntimeException('Collector export failed.',0,$error);}}
if(($mode==='outage')!==$failed||WorkContext::current()!==null){throw new RuntimeException('Collector outcome contract failed.');}
if(!$failed&&$result!==['exported'=>3,'dropped'=>0]){throw new RuntimeException('Unexpected export count.');}
// No implicit resend: the drained request does not become an unbounded retry buffer.
if($telemetry->snapshot()['spans']!==[]||$telemetry->snapshot()['metrics']!==[]){throw new RuntimeException('Exporter retained a hidden retry queue.');}
echo json_encode(['passed'=>true,'mode'=>$mode,'exported'=>$result['exported']??0,'failed'=>$failed,'elapsed_ms'=>round((hrtime(true)-$start)/1e6)],JSON_THROW_ON_ERROR)."\n";
