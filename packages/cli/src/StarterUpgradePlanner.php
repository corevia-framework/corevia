<?php
declare(strict_types=1);
namespace Corevia\Cli;
use Corevia\Core\FrameworkResources;

/** Compare the original starter inventory, application edits and current templates; never write. */
final class StarterUpgradePlanner
{
    public static function plan(string $directory): array
    {
        $root=realpath($directory);
        if($root===false||!is_file($root.'/corevia')||!is_file($root.'/autoload.php')){throw new \InvalidArgumentException('Choose an existing Corevia application.');}
        $marker=$root.'/.corevia-auth-starter.json';
        if(!is_file($marker)||is_link($marker)||filesize($marker)>262144){throw new \InvalidArgumentException('A bounded original auth-starter inventory is required.');}
        $wire=file_get_contents($marker,false,null,0,262145);
        if($wire===false||strlen($wire)>262144){throw new \InvalidArgumentException('Cannot read a bounded starter inventory.');}
        $original=json_decode($wire,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($original)||($original['format']??null)!==1||!is_string($original['stack']??null)
            ||!is_array($original['files']??null)||count($original['files'])<1||count($original['files'])>512){
            throw new \InvalidArgumentException('Invalid original auth-starter inventory.');
        }
        $templates=(new StarterInstaller(FrameworkResources::root()))->files($original['stack']);
        $hashes=[];foreach($templates as $path=>$bytes){$hashes[$path]=hash('sha256',$bytes);}
        foreach($original['files'] as $path=>$hash){
            if(!is_string($path)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){
                throw new \InvalidArgumentException('Invalid starter inventory entry.');
            }
            self::path($root,$path);
        }
        $paths=array_unique([...array_keys($original['files']),...array_keys($templates)]);sort($paths,SORT_STRING);$rows=[];$counts=[];
        foreach($paths as $path){
            $file=self::path($root,$path);$old=$original['files'][$path]??null;$new=$hashes[$path]??null;
            if(file_exists($file)&&!is_file($file)){throw new \LogicException('Starter path is not a regular file.');}
            if(is_file($file)&&filesize($file)>16777216){throw new \RuntimeException('Starter comparison file exceeds 16MiB.');}
            $current=is_file($file)?hash_file('sha256',$file):null;
            if($current===false){throw new \RuntimeException('Cannot read starter comparison file.');}
            if($new===null){$state=$current===null?'obsolete_absent':($current===$old?'obsolete':'obsolete_modified');}
            elseif($current===$new){$state='already_current';}
            elseif($old===null){$state=$current===null?'new_file':'conflict';}
            elseif($current===null){$state='deleted_by_application';}
            else{$state=$current===$old?'update_available':'conflict';}
            $rows[]=['path'=>$path,'status'=>$state];$counts[$state]=($counts[$state]??0)+1;
        }
        ksort($hashes,SORT_STRING);ksort($counts,SORT_STRING);
        return ['format'=>1,'starter'=>'auth','stack'=>$original['stack'],
            'template_fingerprint'=>hash('sha256',\Corevia\Core\CanonicalJson::encode($hashes)),
            'mode'=>'preview_only','files_written'=>false,'can_apply'=>false,
            'requires_review'=>count(array_filter($rows,static fn(array $row):bool=>!in_array($row['status'],['already_current','obsolete_absent'],true)))>0,
            'counts'=>$counts,'files'=>$rows,
            'limits'=>['No files, inventory markers, dependencies or databases are changed.',
                'Edited/deleted application files and obsolete templates require an explicit merge decision.',
                'This checks auth-starter files only; framework, AI, database and configuration migrations need separate plans.']];
    }

    private static function path(string $root,string $path): string
    {
        if(!preg_match('/^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/D',$path)
            ||array_intersect(explode('/',$path),['.','..'])){throw new \InvalidArgumentException('Unsafe starter inventory path.');}
        $cursor=$root;
        foreach(explode('/',$path) as $part){$cursor.='/'.$part;if(is_link($cursor)){throw new \LogicException('Starter plans cannot follow symlinks.');}}
        return $cursor;
    }
}
