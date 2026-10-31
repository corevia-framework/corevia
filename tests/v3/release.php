<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\ReleaseReadiness;
$storage=realpath(dirname(__DIR__,2).'/storage');$root=$storage.'/release-gate-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/storage',0700);mkdir($root.'/docs');mkdir($root.'/docs/v3');
function releaseAssert(bool $value):void{if(!$value){throw new RuntimeException('Release evidence assertion failed.');}}
function releaseGit(string $root,array $arguments):void{
    $pipes=[];$process=proc_open(['git','-c','safe.directory='.str_replace('\\','/',$root),'-C',$root,...$arguments],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process)){throw new RuntimeException('Cannot start release fixture Git.');}fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);releaseAssert(proc_close($process)===0);
}
try{
    $plan=['reviews'=>[],'external_checks'=>['mysql_postgres_mongodb','production_runtime'],'deferred_checks'=>['real_oauth'=>['status'=>'deferred','reason'=>'Explicitly deferred by maintainer'],'docker_image'=>['status'=>'deferred','reason'=>'Optional image requires target validation']]];
    file_put_contents($root.'/.gitignore',"/storage/\n");file_put_contents($root.'/source.php','<?php // fixture');file_put_contents($root.'/docs/v3/acceptance.json',json_encode($plan));
    releaseGit($root,['init','-q']);releaseGit($root,['add','.']);releaseGit($root,['-c','user.name=Corevia fixture','-c','user.email=fixture@example.test','commit','-qm','fixture']);$fingerprint=ReleaseReadiness::fingerprint($root);
    file_put_contents($root.'/storage/v3-verification.json',json_encode(['passed'=>true,'browser_matrix'=>true,'source_fingerprint'=>$fingerprint,'external'=>[]]));
    $stage=['format'=>1,'source_fingerprint'=>$fingerprint,'checks'=>['mysql_postgres_mongodb'=>true,'production_runtime'=>true]];
    file_put_contents($root.'/storage/v3-staging-verification.json',json_encode($stage));$accepted=ReleaseReadiness::report($root);releaseAssert($accepted['ready']&&$accepted['deferred_checks']['real_oauth']['status']==='deferred'&&!isset($accepted['checks']['real_oauth']));
    foreach([
        [...$plan,'deferred_checks'=>['github_ci'=>['status'=>'deferred','reason'=>'Must remain required']]],
        [...$plan,'deferred_checks'=>['real_oauth'=>['status'=>'passed','reason'=>'Cannot call a deferral a pass']]],
        [...$plan,'external_checks'=>['real_oauth']],
        [...$plan,'deferred_checks'=>['real_oauth'=>['status'=>'deferred','reason'=>'']]],
    ] as $invalidPlan){file_put_contents($root.'/docs/v3/acceptance.json',json_encode($invalidPlan));$rejected=false;try{ReleaseReadiness::report($root);}catch(LogicException){$rejected=true;}releaseAssert($rejected);}
    file_put_contents($root.'/docs/v3/acceptance.json',json_encode($plan));
    foreach([[...$stage,'source_fingerprint'=>str_repeat('0',64)],[...$stage,'format'=>2],[...$stage,'checks'=>['mysql_postgres_mongodb'=>'true','production_runtime'=>true]]] as $invalid){file_put_contents($root.'/storage/v3-staging-verification.json',json_encode($invalid));releaseAssert(!ReleaseReadiness::report($root)['ready']);}
    file_put_contents($root.'/storage/v3-staging-verification.json',json_encode($stage));file_put_contents($root.'/source.php','<?php // changed source');$report=ReleaseReadiness::report($root);releaseAssert(!$report['ready']&&!$report['checks']['evidence_matches_sources']&&!$report['checks']['mysql_postgres_mongodb']);
    echo "PASS source-bound local/staging evidence, stale-source rejection and strict boolean/format release gates\n";
}finally{
    $resolved=realpath($root);$normalize=static fn(string $path):string=>strtolower(str_replace('\\','/',$path));
    if($resolved===false||!str_starts_with($normalize($resolved).'/',rtrim($normalize($storage),'/').'/release-gate-')||is_link($root)){throw new RuntimeException('Unsafe release fixture cleanup.');}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{chmod($file->getPathname(),0600);unlink($file->getPathname());}}unset($files,$file);gc_collect_cycles();
    $removed=false;for($attempt=0;$attempt<10;$attempt++){if(@rmdir($resolved)){$removed=true;break;}usleep(100000);}if(!$removed){throw new RuntimeException('Cannot remove private release fixture directory.');}
}
