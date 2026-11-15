<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Deadline,TraceContext,WorkContext};
use Corevia\Http\NativeHttpClient;
$origin=$argv[1]??'';if (!preg_match('#^http://127\.0\.0\.1:[0-9]+$#D',$origin)) { throw new LogicException('Use the loopback HTTP runner.'); }
$client=new NativeHttpClient(allowLoopback:true,timeout:2);$trace=new TraceContext(str_repeat('e',32),str_repeat('f',16));
$echo=WorkContext::run(new Deadline(1000),fn()=>iterator_to_array($client->stream('GET',$origin.'/echo')),$trace);
if (json_decode(implode('',$echo),true)['traceparent']!==$trace->header()) { throw new RuntimeException('Native HTTP trace lost.'); }
$failed=false;try { iterator_to_array($client->stream('GET',$origin.'/redirect')); } catch (RuntimeException) { $failed=true; }
if (!$failed) { throw new RuntimeException('Native HTTP redirect followed.'); }
$failed=false;$start=hrtime(true);
try { WorkContext::run(new Deadline(150),fn()=>iterator_to_array($client->stream('GET',$origin.'/trickle'))); } catch (RuntimeException) { $failed=true; }
if (!$failed||(hrtime(true)-$start)/1e9>1.5) { throw new RuntimeException('Native HTTP total deadline failed.'); }
if (WorkContext::current()!==null) { throw new RuntimeException('Native HTTP context leaked.'); }
echo "PASS native HTTP trace, redirect rejection and slow-stream deadline\n";
