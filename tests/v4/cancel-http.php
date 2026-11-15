<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Protocol\{RemoteAction,Protocol};
use Corevia\Http\{NativeHttpClient,RemoteCancellation};
$origin=$argv[1];$mode=$argv[2];$id=$argv[3];$capability=$argv[4];$token=$argv[5]??'fixture';
if($mode==='call'){
    $result=(new RemoteAction($origin.'/cvp','fixture',10.0,version:Protocol::V2))->call('slow',[],requestId:$id,cancelCapability:$capability);
    echo json_encode(['ok'=>$result->ok,'code'=>$result->error?->code],JSON_THROW_ON_ERROR)."\n";
}else{
    (new RemoteCancellation(new NativeHttpClient(allowLoopback:true,timeout:2),$origin.'/cvp/cancel',['Authorization'=>'Bearer '.$token],allowLoopback:true))->request($id,$capability);
    echo "accepted\n";
}
