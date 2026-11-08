<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\ActionResult;
use Corevia\Protocol\Protocol;
$protocol=new Protocol();$request=$protocol->decode(trim(fgets(STDIN)));
$result=getenv('COREVIA_TEST_PARENT_SECRET')!==false?ActionResult::failure('ENVIRONMENT_LEAK','Child inherited an unlisted variable.'):
    ActionResult::success(['value'=>($request['payload']['input']['value']??0)*2]);
echo $protocol->encode(Protocol::response($request['id'],$result,version:Protocol::V2));
