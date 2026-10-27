<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\ActionResult;
use Corevia\Protocol\Protocol;
if (($argv[1]??'')==='crash') { exit(7); }
if (($argv[1]??'')==='sleep') { usleep(200000); }
$p=new Protocol(); $request=$p->decode(trim(stream_get_contents(STDIN)));
echo $p->encode(Protocol::response($request['id'],ActionResult::success($request['payload']),$request['meta']['trace_id'], $request['cvp']));
