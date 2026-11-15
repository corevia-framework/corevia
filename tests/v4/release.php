<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\{ReleaseReadiness,V4ReleaseReadiness};
$root=dirname(__DIR__,2).'/storage/v4-release-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/storage',0700);mkdir($root.'/docs/v4',0700,true);
function gate(bool $value):void{if(!$value){throw new RuntimeException('V4 release gate contract failed.');}}
function gateGit(string $root,array $args):string{$p=proc_open(['git','-c','safe.directory='.str_replace('\\','/',$root),'-C',$root,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);gate(proc_close($p)===0);return trim($out);}
$plan=['format'=>1,'target'=>\Corevia\Core\Version::VERSION,'reviews'=>array_fill_keys(['scope_review','security_boundary_review','upgrade_review','support_matrix'],true),'required_checks'=>['fixture'],'deferred_checks'=>['real_oidc'=>['status'=>'deferred','reason'=>'No provider account','guide'=>'docs/v4/optional-validation.md']]];
$save=static function(string $name,array $value)use($root):void{file_put_contents($root.'/'.$name,json_encode($value,JSON_THROW_ON_ERROR));};
file_put_contents($root.'/.gitignore',"/storage/\n");file_put_contents($root.'/source.php','<?php // original');$save('docs/v4/acceptance.json',$plan);
gateGit($root,['init','-q']);gateGit($root,['add','.']);gateGit($root,['-c','user.name=fixture','-c','user.email=fixture@example.test','commit','-qm','fixture']);
$commit=gateGit($root,['rev-parse','HEAD']);$evidence=['local_passed'=>true,'source_unchanged'=>true,'source'=>['sha256'=>V4ReleaseReadiness::fingerprint($root)],'checks'=>[['name'=>'fixture','exit_code'=>0]]];
$save('storage/v4-verification.json',$evidence);$ci=['commit'=>$commit,'conclusion'=>'success','run_ids'=>[123]];$save('storage/v4-ci-verification.json',$ci);
gate(ReleaseReadiness::report($root)['ready']);
$save('storage/v4-ci-verification.json',[...$ci,'run_ids'=>['123']]);gate(ReleaseReadiness::report($root)['ready']);$save('storage/v4-ci-verification.json',$ci);
// Application-owned untracked output is not framework release source.
file_put_contents($root.'/user-app.txt','application-owned');gate(ReleaseReadiness::report($root)['ready']);
foreach([
    [...$evidence,'local_passed'=>'true'],
    [...$evidence,'checks'=>[]],
    [...$evidence,'checks'=>[['name'=>'fixture','exit_code'=>'0']]],
    [...$evidence,'checks'=>[['name'=>'fixture','exit_code'=>0],['name'=>'fixture','exit_code'=>0]]],
    [...$evidence,'source'=>['sha256'=>str_repeat('0',64)]],
]as $bad){$save('storage/v4-verification.json',$bad);gate(!ReleaseReadiness::report($root)['ready']);}
$save('storage/v4-verification.json',$evidence);
foreach([[...$ci,'commit'=>str_repeat('0',40)],[...$ci,'conclusion'=>'failure'],[...$ci,'run_ids'=>[]],[...$ci,'run_ids'=>[true]]]as $bad){$save('storage/v4-ci-verification.json',$bad);gate(!ReleaseReadiness::report($root)['ready']);}
$save('storage/v4-ci-verification.json',$ci);
foreach(['github_ci','local_suite']as $id){$save('docs/v4/acceptance.json',[...$plan,'deferred_checks'=>[$id=>['status'=>'deferred','reason'=>'Not allowed','guide'=>'docs/v4/optional-validation.md']]]);$rejected=false;try{ReleaseReadiness::report($root);}catch(LogicException){$rejected=true;}gate($rejected);}
$save('docs/v4/acceptance.json',$plan);file_put_contents($root.'/source.php','<?php // changed');$report=ReleaseReadiness::report($root);gate(!$report['ready']&&!$report['checks']['evidence_matches_sources']&&!$report['checks']['clean_tracked_worktree']);
echo "PASS V4 version/source/CI binding, strict local evidence, required checks, deferral rejection and application-file preservation\n";
