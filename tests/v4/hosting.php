<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\HostingInspector;
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Hosting contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid hosting policy accepted.');}
$facts=['php_version_id'=>80300,'php_version'=>'8.3.fixture','sapi'=>'cli','os'=>'Fixture',
    'extensions'=>['json','openssl'],'functions'=>['json_encode','json_decode','random_bytes','hash','hash_hmac','fopen','flock','rename','stream_socket_server'],
    'public_directory'=>true,'private_layout'=>true,'private_writable_storage'=>true,'memory_bytes'=>134217728,'execution_seconds'=>30];
foreach(['shared','vps','aws','azure'] as $target){
    $report=HostingInspector::evaluate($target,[],$facts);
    check($report['baseline_compatible']&&!$report['blocked']&&!$report['production_ready']&&!$report['target_validated']);
    check(count(array_filter($report['checks'],fn($row)=>$row['status']==='unverified'))===2);
}
$policy=['features'=>['cron','python','websocket','outbound_https'],'declarations'=>['https'=>true,'document_root'=>true,'cron'=>true,'python'=>true,'websocket'=>true,'outbound_https'=>true]];
$report=HostingInspector::evaluate('shared',$policy,$facts);
check(!$report['blocked']&&!$report['production_ready']);
check(count(array_filter($report['checks'],fn($row)=>$row['status']==='unverified'))===6);
check(HostingInspector::evaluate('aws',['declarations'=>['cron'=>false],'features'=>['cron']],$facts)['blocked']);
check(HostingInspector::evaluate('vps',['extensions'=>['pdo_mysql']],$facts)['blocked']);
foreach(['private_layout','private_writable_storage','public_directory'] as $key){check(HostingInspector::evaluate('shared',[],array_replace($facts,[$key=>false]))['blocked']);}
check(HostingInspector::evaluate('azure',['minimum_memory_mb'=>256],$facts)['blocked']);
check(!HostingInspector::evaluate('azure',['minimum_memory_mb'=>256],array_replace($facts,['memory_bytes'=>null]))['blocked']);
foreach([['features'=>['unknown']],['extensions'=>[123]],['minimum_memory_mb'=>'64'],['declarations'=>['https'=>'true']],['declarations'=>['secret'=>'DO_NOT_PRINT']],['password'=>'DO_NOT_PRINT']] as $bad){rejects(fn()=>HostingInspector::evaluate('shared',$bad,$facts));}
rejects(fn()=>HostingInspector::evaluate('unknown',[],$facts));
check(HostingInspector::memoryBytes('128M')===134217728&&HostingInspector::memoryBytes('-1')===null&&HostingInspector::memoryBytes('1G')===1073741824);
rejects(fn()=>HostingInspector::memoryBytes('128MB'));
$root=dirname(__DIR__,2);$actual=HostingInspector::inspect($root,'shared');
check(!$actual['target_validated']&&!$actual['external_services_checked']);
check(HostingInspector::inspect($root,'shared',[],$root)['blocked']);
check(!str_contains(json_encode($actual,JSON_THROW_ON_ERROR),$root));
echo "PASS hosting requirements, fail-closed capability checks, declaration provenance, memory bounds and private layout\n";
