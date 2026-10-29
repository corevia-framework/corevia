<?php
declare(strict_types=1);
namespace Corevia\Cli;
use Corevia\Core\CanonicalJson;

/** Additive starter installation: never overwrite an application file. */
final readonly class StarterInstaller
{
    public function __construct(private string $frameworkRoot){}
    public function files(string $stack):array
    {
        if(!in_array($stack,['bootstrap','tailwind','react'],true)){throw new \InvalidArgumentException('Choose bootstrap, tailwind or react.');}
        $source=$this->frameworkRoot.'/starters/auth';$files=[];
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source.'/common',\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){if($file->isLink() || !$file->isFile()){throw new \RuntimeException('Invalid starter template.');}$name=str_replace('\\','/',substr($file->getPathname(),strlen($source.'/common/') ));$name=substr($name,0,-5);
            if(($stack==='react' && in_array($name,['resources/views/auth/page.php','resources/views/auth/account.php'],true)) || ($stack!=='react' && $name==='resources/views/auth/shell.php')){continue;}
            $files[$name]=str_replace('{{stack}}',$stack,file_get_contents($file->getPathname()));
        }
        foreach(['package.json','package-lock.json'] as $name){$files['frontend/auth/'.$name]=file_get_contents($source.'/stacks/'.$stack.'/'.$name);}
        $files['frontend/auth/build.mjs']=file_get_contents($source.'/frontend/build.mjs');
        if($stack!=='react'){$files['frontend/auth/src/account.js']=file_get_contents($source.'/frontend/src/account.js');}
        if($stack!=='bootstrap'){$files['frontend/auth/src/auth.css']=file_get_contents($source.'/frontend/src/auth.css');if($stack!=='react'){$files['frontend/auth/src/auth.css']=str_replace('@source "./app.jsx";','',$files['frontend/auth/src/auth.css']);}}
        if($stack==='react'){$files['frontend/auth/src/app.jsx']=file_get_contents($source.'/frontend/src/app.jsx');}
        $assets=json_decode(file_get_contents($source.'/assets/manifest.json'),true,64,JSON_THROW_ON_ERROR)['stacks'][$stack]['files']??throw new \RuntimeException('Missing asset manifest.');
        foreach($assets as $name=>$hash){if(basename($name)!==$name || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$name)){throw new \RuntimeException('Invalid bundled asset name.');}$bytes=file_get_contents($source.'/assets/'.$stack.'/'.$name);if(!hash_equals($hash,hash('sha256',$bytes))){throw new \RuntimeException('Bundled auth asset integrity mismatch.');}$files['public/assets/'.$name]=$bytes;}
        if(!isset($files['public/assets/auth.css']) || ($stack==='react' && !isset($files['public/assets/auth.js']))){throw new \RuntimeException('Bundled starter assets are missing; rebuild them before distributing.');}
        ksort($files,SORT_STRING);return $files;
    }
    public function install(string $stack,string $directory,bool $dryRun=false,bool $check=false):array
    {
        $root=realpath($directory);if($root===false || !is_file($root.'/corevia') || !is_file($root.'/autoload.php') || !is_file($root.'/packages/auth/src/AuthService.php')){throw new \InvalidArgumentException('Install into an existing V3 Corevia app with the auth package.');}
        $files=$this->files($stack);$hashes=[];foreach($files as $name=>$content){$hashes[$name]=hash('sha256',$content);}
        $marker='.corevia-auth-starter.json';$files[$marker]=CanonicalJson::encode(['format'=>1,'stack'=>$stack,'files'=>$hashes])."\n";
        foreach($files as $name=>$content){$path=$root;foreach(explode('/',$name) as $part){$path.='/'.$part;if(is_link($path)){throw new \LogicException('Starter output may not follow symlinks.');}}}
        if($check){foreach($files as $name=>$content){if(!is_file($root.'/'.$name) || file_get_contents($root.'/'.$name)!==$content){throw new \LogicException('Starter file is missing or edited: '.$name);}}return ['stack'=>$stack,'checked'=>true,'files'=>array_keys($files)];}
        foreach($files as $name=>$content){if(file_exists($root.'/'.$name)){throw new \LogicException('Refusing to overwrite: '.$name);}}
        if($dryRun){return ['stack'=>$stack,'dry_run'=>true,'files'=>array_keys($files)];}
        $storage=$root.'/storage';if(!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)){throw new \RuntimeException('Cannot create installer storage.');}
        if(is_link($storage) || is_link($storage.'/.auth-installer.lock')){throw new \LogicException('Installer lock cannot use symlinks.');}
        $lock=fopen($storage.'/.auth-installer.lock','c+b');if($lock===false || !flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock starter installation.');}
        $created=[];
        try{
            foreach($files as $name=>$content){$path=$root.'/'.$name;if(!is_dir(dirname($path)) && !mkdir(dirname($path),0755,true) && !is_dir(dirname($path))){throw new \RuntimeException('Cannot create starter directory.');}$handle=@fopen($path,'x+b');if($handle===false){throw new \LogicException('Refusing to overwrite: '.$name);}$created[]=$path;try{if(fwrite($handle,$content)!==strlen($content)){throw new \RuntimeException('Cannot write starter file.');}}finally{fclose($handle);}}
        }catch(\Throwable $error){foreach(array_reverse($created) as $path){unlink($path);}throw $error;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
        return ['stack'=>$stack,'installed'=>true,'login'=>'/login','files'=>array_keys($files)];
    }
}
