<?php
declare(strict_types=1);
namespace Corevia\Cli;
final class ReleaseReadiness
{
    private static function git(string $root,array $args):string
    {
        $pipes=[];$process=proc_open(['git','-c','safe.directory='.str_replace('\\','/',$root),...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($process)){throw new \RuntimeException('Git is required to verify the working tree.');}fclose($pipes[0]);$output=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0){throw new \RuntimeException('Cannot inspect Git source state.');}return $output;
    }
    public static function fingerprint(string $root):string
    {
        $paths=array_values(array_filter(explode("\0",self::git($root,['ls-files','--cached','--others','--exclude-standard','-z']))));sort($paths,SORT_STRING);$hash=hash_init('sha256');foreach($paths as $path){if(is_link($root.'/'.$path)||!is_file($root.'/'.$path)){throw new \RuntimeException('Release sources contain missing files or symlinks.');}hash_update($hash,$path."\0".hash_file('sha256',$root.'/'.$path,true));}return hash_final($hash);
    }
    public static function report(string $root):array
    {
        $plan=json_decode(file_get_contents($root.'/docs/v3/acceptance.json'),true,32,JSON_THROW_ON_ERROR);$deferred=$plan['deferred_checks']??[];
        foreach($deferred as $id=>$detail){if(!in_array($id,['real_oauth','docker_image'],true)||!is_array($detail)||($detail['status']??null)!=='deferred'||!is_string($detail['reason']??null)||trim($detail['reason'])===''||in_array($id,$plan['external_checks'],true)){throw new \LogicException('Invalid or conflicting optional-check deferral.');}}
        $file=$root.'/storage/v3-verification.json';$evidence=is_file($file)&&filesize($file)<=65536?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];
        $fingerprint=self::fingerprint($root);$stagingFile=$root.'/storage/v3-staging-verification.json';$staging=is_file($stagingFile)&&filesize($stagingFile)<=262144?json_decode(file_get_contents($stagingFile),true,32,JSON_THROW_ON_ERROR):[];
        $stagingMatches=($staging['format']??null)===1&&is_string($staging['source_fingerprint']??null)&&hash_equals($fingerprint,$staging['source_fingerprint']);
        $checks=['local_checks_passed'=>($evidence['passed']??false)===true,'browser_matrix'=>($evidence['browser_matrix']??false)===true,'evidence_matches_sources'=>is_string($evidence['source_fingerprint']??null)&&hash_equals($fingerprint,$evidence['source_fingerprint']),'clean_worktree'=>trim(self::git($root,['status','--porcelain']))===''];foreach($plan['reviews'] as $id=>$value){$checks[$id]=$value===true;}foreach($plan['external_checks'] as $id){$checks[$id]=($evidence['external'][$id]??false)===true||($stagingMatches&&($staging['checks'][$id]??false)===true);}
        return ['ready'=>!in_array(false,$checks,true),'target'=>'3.0.0','published'=>false,'checks'=>$checks,'deferred_checks'=>$deferred,'evidence_kind'=>'local/operator-supplied, not release certification'];
    }
}
