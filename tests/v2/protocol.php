<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\ActionResult;
use Corevia\Protocol\{ProcessTransport, Protocol};
$fixtures=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures.json'),true,64,JSON_THROW_ON_ERROR);
$protocol=new Protocol(); $count=0;
foreach ($fixtures['valid'] as $value) { $protocol->decode($protocol->encode($value)); $count++; }
foreach ($fixtures['invalid'] as $value) { try { $protocol->validate($value); throw new RuntimeException('Accepted invalid fixture'); } catch (InvalidArgumentException) { $count++; } }
$reply=Protocol::response('req_1',ActionResult::success(['ok'=>true]),'trace',Protocol::V2,'parent'); $protocol->validate($reply);
if ($reply['type']!=='action.result' || $reply['meta']['parent_trace_id']!=='parent') { throw new RuntimeException('Reply metadata lost'); }
$worker=__DIR__.'/process-worker.php';
$result=(new ProcessTransport([PHP_BINARY,$worker]))->call('echo',['value'=>42]);
if (!$result->ok || $result->data['value']!==42) { throw new RuntimeException('Process round trip failed: '.json_encode($result)); }
$result=(new ProcessTransport([PHP_BINARY,$worker,'crash']))->call('echo',[]);
if ($result->error?->code!=='PROCESS_CRASH') { throw new RuntimeException('Crash not classified'); }
$result=(new ProcessTransport([PHP_BINARY,$worker,'sleep'],25))->call('echo',[]);
if ($result->error?->code!=='PROCESS_TIMEOUT') { throw new RuntimeException('Timeout not enforced'); }
echo "PASS $count CVP/2 fixtures, correlated response, process round trip, worker crash and timeout\n";
