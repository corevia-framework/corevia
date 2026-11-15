<?php
declare(strict_types=1);
namespace Corevia\Cli;

/** Offline, operator-supplied evidence checks; never a security certification. */
final class V4ReleaseReadiness
{
    private const DEFERRED = ['shared_hosting','aws','azure','docker_image','real_oidc','physical_passkeys','other_mobile','manual_accessibility','independent_security','long_workloads','model_quality','os_tool_sandbox','packagist'];

    private static function git(string $root,array $arguments):string
    {
        $process=proc_open(['git','-c','safe.directory='.str_replace('\\','/',$root),...$arguments],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        if(!is_resource($process)){throw new \RuntimeException('Git is required for release evidence.');}
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0){throw new \RuntimeException('Cannot read release Git state.');}
        return $output;
    }

    public static function fingerprint(string $root):string
    {
        $files=[];$paths=array_values(array_filter(explode("\0",self::git($root,['ls-files','--cached','-z']))));sort($paths,SORT_STRING);
        foreach($paths as $path){if(is_link($root.'/'.$path)||!is_file($root.'/'.$path)){throw new \RuntimeException('Release source must contain regular existing files.');}$files[$path]=hash_file('sha256',$root.'/'.$path);}
        if($files===[]){throw new \RuntimeException('Release source is empty.');}
        return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function json(string $path):array
    {
        if(!is_file($path)){return [];}
        if(is_link($path)||filesize($path)>8388608){throw new \LogicException('Release evidence must be a bounded regular file.');}
        $value=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($value)){throw new \LogicException('Invalid release evidence.');}
        return $value;
    }

    public static function report(string $root):array
    {
        $plan=self::json($root.'/docs/v4/acceptance.json');
        if(($plan['format']??null)!==1||($plan['target']??null)!==\Corevia\Core\Version::VERSION||!is_array($plan['required_checks']??null)||$plan['required_checks']===[]){throw new \LogicException('Invalid V4 release plan/version.');}
        $deferred=$plan['deferred_checks']??[];
        foreach($deferred as $id=>$detail){if(!in_array($id,self::DEFERRED,true)||!is_array($detail)||($detail['status']??null)!=='deferred'||!is_string($detail['reason']??null)||trim($detail['reason'])===''||($detail['guide']??null)!=='docs/v4/optional-validation.md'){throw new \LogicException('Invalid V4 deferral; local tests, CI and release integrity cannot be waived.');}}
        $evidence=self::json($root.'/storage/v4-verification.json');$ci=self::json($root.'/storage/v4-ci-verification.json');
        $names=[];$valid=true;
        foreach($evidence['checks']??[] as $check){if(!is_array($check)||!is_string($check['name']??null)||($check['exit_code']??null)!==0||isset($names[$check['name']])){$valid=false;continue;}$names[$check['name']]=true;}
        foreach($plan['required_checks'] as $name){if(!is_string($name)||!isset($names[$name])){$valid=false;}}
        $fingerprint=self::fingerprint($root);$commit=trim(self::git($root,['rev-parse','HEAD']));
        $runIds=$ci['run_ids']??[];$validRuns=is_array($runIds)&&$runIds!==[];
        foreach(is_array($runIds)?$runIds:[] as $id){if(!(is_int($id)&&$id>0)&&!(is_string($id)&&ctype_digit($id)&&(int)$id>0)){$validRuns=false;}}
        $checks=[
            'local_suite'=>($evidence['local_passed']??null)===true&&($evidence['source_unchanged']??null)===true&&$valid,
            'evidence_matches_sources'=>is_string($evidence['source']['sha256']??null)&&hash_equals($fingerprint,$evidence['source']['sha256']),
            'clean_tracked_worktree'=>trim(self::git($root,['status','--porcelain','--untracked-files=no']))==='',
            'github_ci'=>($ci['commit']??null)===$commit&&($ci['conclusion']??null)==='success'&&$validRuns,
        ];
        foreach(['scope_review','security_boundary_review','upgrade_review','support_matrix'] as $name){$checks[$name]=($plan['reviews'][$name]??null)===true;}
        return ['ready'=>!in_array(false,$checks,true),'target'=>$plan['target'],'published'=>false,'source_commit'=>$commit,'checks'=>$checks,'deferred_checks'=>$deferred,'evidence_kind'=>'offline operator-supplied source/CI evidence; not independent certification'];
    }
}
