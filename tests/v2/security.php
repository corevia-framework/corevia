<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{JsonRunStore,JobQueue};
use Corevia\Protocol\{Protocol,RemoteAction};
function rejected(callable $call):void { try{$call();}catch(Throwable){return;}throw new RuntimeException('Unsafe input accepted'); }
$p=new Protocol(1024);
rejected(fn()=>new RemoteAction('https://example.com/cvp'));
rejected(fn()=>new RemoteAction('http://user:password@127.0.0.1/cvp'));
rejected(fn()=>$p->decode(str_repeat('x',1025)));
rejected(fn()=>$p->decode('{"cvp":"2.0","id":"req","type":"shell.exec","target":"eval","payload":{}}'));
rejected(fn()=>$p->decode('{"cvp":"2.0","id":"req","type":"action.call","target":"echo","payload":{},"meta":{"timeout_ms":-1}}'));
$cycle=[];$cycle['self']=&$cycle;
rejected(fn()=>$p->encode(Protocol::request('ping',payload:$cycle,version:Protocol::V2)));
$directory=sys_get_temp_dir().'/corevia-security-'.bin2hex(random_bytes(8));mkdir($directory);
try {
    $path=$directory.'/runs.json';file_put_contents($path,'corrupt');$store=new JsonRunStore($path);rejected(fn()=>$store->get('run'));if(file_get_contents($path)!=='corrupt'){throw new RuntimeException('Corrupt storage overwritten');}
    $queue=new JobQueue($directory.'/jobs');rejected(fn()=>$queue->dispatch('flow',['huge'=>str_repeat('x',1048577)]));
    if(glob($directory.'/jobs/*')!==[]){throw new RuntimeException('Oversized dispatch left partial jobs');}
    echo "PASS protocol bounds, unknown message denial, cyclic serialization, corrupt-store preservation and queue size limits\n";
}finally{foreach(glob($directory.'/*') as $path){if(is_file($path)){unlink($path);}}rmdir($directory.'/jobs');rmdir($directory);}
