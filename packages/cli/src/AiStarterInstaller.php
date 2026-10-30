<?php
declare(strict_types=1);
namespace Corevia\Cli;
final readonly class AiStarterInstaller
{
    public function __construct(private string $base){}
    public function install(string $path,bool $dryRun=false):array
    {
        $root=realpath($path);if($root===false||!is_file($root.'/routes/auth.php')||!is_file($root.'/packages/ai/src/AiClient.php')){throw new \LogicException('Install into a V3 application with an auth starter first.');}
        $files=[];foreach(['config.php.stub'=>'config/ai.php','routes.php.stub'=>'routes/ai.php','chat.php.stub'=>'resources/views/ai/chat.php','app.js'=>'public/assets/ai.js'] as $source=>$target){$files[$target]=file_get_contents($this->base.'/starters/ai/'.$source);}$files['public/assets/corevia-ai.js']=file_get_contents($this->base.'/sdk/js/ai.js');
        foreach($files as $name=>$content){$cursor=$root;foreach(explode('/',$name) as $part){$cursor.='/'.$part;if(is_link($cursor)){throw new \LogicException('Starter cannot use symlinks.');}}if(file_exists($cursor)){throw new \LogicException('Refusing to overwrite: '.$name);}}
        if($dryRun){return ['dry_run'=>true,'files'=>array_keys($files)];}$lockPath=$root.'/storage/.ai-installer.lock';if(is_link($lockPath)||is_link(dirname($lockPath))){throw new \LogicException('Unsafe starter lock.');}$lock=fopen($lockPath,'c+b');if($lock===false||!flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock AI installation.');}$created=[];
        try{foreach($files as $name=>$content){$target=$root.'/'.$name;if(!is_dir(dirname($target))&&!mkdir(dirname($target),0755,true)&&!is_dir(dirname($target))){throw new \RuntimeException('Cannot create AI directory.');}$file=@fopen($target,'x+b');if($file===false){throw new \LogicException('Refusing to overwrite: '.$name);}$created[]=$target;try{if(fwrite($file,$content)!==strlen($content)){throw new \RuntimeException('Cannot write AI starter.');}}finally{fclose($file);}}}catch(\Throwable $error){foreach(array_reverse($created) as $file){unlink($file);}throw $error;}finally{flock($lock,LOCK_UN);fclose($lock);}
        return ['installed'=>true,'page'=>'/ai','files'=>array_keys($files)];
    }
}
