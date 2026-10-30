<?php
declare(strict_types=1);
namespace CoreviaOperationsTests;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\DeploymentInspector;
use Corevia\Auth\SecretBox;
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Operations assertion failed.');}}
$root=dirname(__DIR__,2);$storage=sys_get_temp_dir().'/corevia-operations-'.bin2hex(random_bytes(6));mkdir($storage);$names=['APP_ENV','APP_URL','COREVIA_STORAGE','RUN_STORE','AUTH_SHARED_KEY_PROVISIONED'];$old=[];foreach($names as $name){$old[$name]=getenv($name);}
try{
    putenv('COREVIA_STORAGE='.$storage);putenv('APP_ENV=production');putenv('APP_URL=https://app.example');putenv('RUN_STORE=json');putenv('AUTH_SHARED_KEY_PROVISIONED=false');
    $auth=['url'=>'https://app.example','driver'=>'json','delivery'=>'preview','mfa'=>true,'key_file'=>$storage.'/key','smtp'=>['password'=>'DO_NOT_PRINT_PRIVATE_FIXTURE']];$report=DeploymentInspector::inspect($root,$auth,[],true);$checks=array_column($report['checks'],'ok','id');check(!$report['ready']&&!$checks['shared_sessions']&&!$checks['shared_flow_store']&&!$checks['shared_mail_queue']&&!$checks['persisted_auth_key']&&!$checks['signed_sources']);check(!str_contains(json_encode($report),'DO_NOT_PRINT_PRIVATE_FIXTURE'));
    putenv('RUN_STORE=sql');putenv('AUTH_SHARED_KEY_PROVISIONED=true');$auth['driver']='sql';$auth['delivery']='sqlsmtp';file_put_contents($storage.'/key',random_bytes(32));$report=DeploymentInspector::inspect($root,$auth,['enabled'=>true,'base'=>'http://untrusted.example/v1','loopback'=>true,'model'=>'chosen'],true);$checks=array_column($report['checks'],'ok','id');check($checks['shared_sessions']&&$checks['shared_flow_store']&&$checks['shared_mail_queue']&&$checks['shared_key_provisioned']&&!$checks['ai_transport']&&!$report['external_services_checked']&&!$report['runtime_config_graph_checked']);
    if(function_exists('openssl_encrypt')){$key=SecretBox::fromFile($storage.'/key');$encrypted=$key->seal('fixture','bound-purpose');check(!file_exists($storage.'/key.lock'));check(SecretBox::fromFile($storage.'/key')->open($encrypted,'bound-purpose')==='fixture');}
    echo "PASS deployment multi-host/secret/transport checks, source evidence gate and provisioned-key loading\n";
}finally{foreach($old as $name=>$value){putenv($value===false?$name:$name.'='.$value);}foreach(glob($storage.'/*') as $file){unlink($file);}rmdir($storage);}
