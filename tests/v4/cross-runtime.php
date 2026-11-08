<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Deadline,Telemetry,TraceContext,WorkContext};
use Corevia\Protocol\{ProcessTransport,Protocol,RemoteAction};
$url=$argv[1]??'';$python=$argv[2]??'';
if (!preg_match('#^http://127\.0\.0\.1:[0-9]+/cvp$#D',$url)||$python==='') { throw new LogicException('Use the isolated loopback test runner.'); }
$telemetry=new Telemetry('php-contract',['python.call'=>'client']);$trace=new TraceContext(str_repeat('a',32),str_repeat('b',16));
$remote=new RemoteAction($url,timeout:2,version:Protocol::V2);
$result=WorkContext::run(new Deadline(1500),fn()=>$telemetry->span('python.call',fn()=>$remote->call('inspect',[]),$trace));
if (!$result->ok||$result->data['remaining_ms']>1500||$result->data['traceparent']===null) { throw new RuntimeException('Cross-runtime propagation failed.'); }
$process=WorkContext::run(new Deadline(1500),fn()=>(new ProcessTransport([$python,__DIR__.'/budget-process.py']))->call('inspect',[]));
if (!$process->ok||$process->data['remaining_ms']>1500) { throw new RuntimeException('Process budget propagation failed.'); }
$expired=new Deadline(1000);$expired->cancel();$cancel=null;
try { WorkContext::run($expired,fn()=>$remote->call('inspect',[])); } catch (RuntimeException $error) { $cancel=$error->getMessage(); }
if ($cancel!=='WORK_CANCELLED') { throw new RuntimeException('Cancelled work was dispatched.'); }
$timeout=null;
try {
    $slow=WorkContext::run(new Deadline(150),fn()=>$remote->call('slow',[]));$timeout=$slow->error?->code;
} catch (RuntimeException $error) { $timeout=$error->getMessage(); }
if (!in_array($timeout,['REMOTE_TIMEOUT','DEADLINE_EXCEEDED'],true)||WorkContext::current()!==null) { throw new RuntimeException('Cross-runtime deadline/cleanup failed.'); }
echo json_encode(['result'=>$result->data,'spans'=>$telemetry->snapshot()['spans'],'timeout'=>$timeout],JSON_THROW_ON_ERROR);
