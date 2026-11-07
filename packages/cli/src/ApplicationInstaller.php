<?php
declare(strict_types=1);
namespace Corevia\Cli;
use Corevia\Core\{CanonicalJson,FrameworkResources};

/** Development-machine composition. Composer is explicit and scripts/plugins stay disabled. */
final class ApplicationInstaller
{
    public static function create(string $path,array $features=['web'],string $stack='bootstrap',string $database='json',bool $preview=false,?string $runtimePath=null): array
    {
        if(!in_array($stack,['bootstrap','tailwind','react'],true)||!in_array($database,['json','sqlite','mysql','pgsql','mongodb'],true)){throw new \InvalidArgumentException('Invalid UI stack or database driver.');}
        $plan=FeatureResolver::resolve($features,FeatureResolver::catalog());
        $runtime=realpath($runtimePath??FrameworkResources::root());
        if($runtime===false||!is_file($runtime.'/corevia-package.json')){throw new \LogicException('Use a built V4 runtime package via --runtime-path.');}
        self::verifyRuntime($runtime);
        $skeleton=$runtime.'/starters/application/skeleton';
        if(!is_dir($skeleton)){throw new \LogicException('Application skeleton is missing.');}
        $absolute=self::destination($path);
        $report=['format'=>1,'features'=>$plan,'stack'=>$stack,'database'=>$database,'preview'=>$preview,'scripts_enabled'=>false,'plugins_enabled'=>false];
        if($preview){return $report;}
        if(!mkdir($absolute,0700)){throw new \RuntimeException('Cannot reserve new application directory.');}
        try{
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($skeleton,\FilesystemIterator::SKIP_DOTS)) as $file){
                if($file->isLink()||!$file->isFile()){throw new \LogicException('Unsafe skeleton member.');}
                $relative=str_replace('\\','/',substr($file->getPathname(),strlen($skeleton)+1));
                self::write($absolute,$relative,file_get_contents($file->getPathname()));
            }
            $composer=json_decode(file_get_contents($absolute.'/composer.json'),true,64,JSON_THROW_ON_ERROR);
            $composer['repositories']=[['type'=>'path','url'=>str_replace('\\','/',$runtime),'options'=>['symlink'=>false,'versions'=>['corevia-framework/runtime'=>'dev-v4']]]];
            if($database!=='json'){$composer['require']['ext-'.($database==='mongodb'?'mongodb':'pdo_'.$database)]='*';}
            file_put_contents($absolute.'/composer.json',json_encode($composer,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
            $settings=$absolute.'/config/settings/database.php';
            file_put_contents($settings,str_replace("getenv('DB_CONNECTION') ?: 'local'","getenv('DB_CONNECTION') ?: '".($database==='json'?'local':$database)."'",file_get_contents($settings)));
            if(in_array('api',$plan['order'],true)){
                $runtimeFile=$absolute.'/config/settings/runtime.php';
                $bytes=str_replace('return [',"return ['profile'=>'api',",file_get_contents($runtimeFile),$replacements);
                if($replacements!==1){throw new \LogicException('Unsupported runtime configuration template.');}
                file_put_contents($runtimeFile,$bytes);
            }
            self::composer($absolute);
            if(in_array('auth',$plan['order'],true)){(new StarterInstaller($runtime))->install($stack,$absolute);}
            if(in_array('ai',$plan['order'],true)){(new AiStarterInstaller($runtime))->install($absolute);}
            $owned=[];
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute,\FilesystemIterator::SKIP_DOTS)) as $file){
                $name=str_replace('\\','/',substr($file->getPathname(),strlen($absolute)+1));
                if($file->isFile()&&!str_starts_with($name,'vendor/')&&!str_starts_with($name,'storage/')){$owned[$name]=hash_file('sha256',$file->getPathname());}
            }
            ksort($owned,SORT_STRING);
            self::write($absolute,'.corevia-application.json',CanonicalJson::encode(['format'=>1,'features'=>$plan,'stack'=>$stack,'database'=>$database,'files'=>$owned])."\n");
            return $report+['installed'=>true,'application'=>$absolute];
        }catch(\Throwable $error){
            // Keep only this newly reserved directory for inspection; never remove user trees.
            throw new \RuntimeException('Application creation failed; its new directory is retained for inspection.',0,$error);
        }
    }

    public static function composer(string $root,bool $updateRuntime=false):void
    {
        $executable=null;
        foreach(explode(PATH_SEPARATOR,getenv('PATH')?:'') as $directory){foreach(['composer.phar','composer'] as $name){$candidate=rtrim($directory,'/\\').'/'.$name;if(is_file($candidate)&&is_readable($candidate)){$executable=$candidate;break 2;}}}
        if($executable===null){throw new \RuntimeException('Composer is required on the build machine.');}
        if(!is_dir($root.'/storage')){mkdir($root.'/storage',0700,true);}
        $environment=getenv();$environment['COMPOSER_ROOT_VERSION']='dev-v4';
        $log=$root.'/storage/composer-install.log';
        $arguments=$updateRuntime?['update','corevia-framework/runtime','--with-dependencies']:['install'];
        $process=proc_open([PHP_BINARY,$executable,...$arguments,'--no-interaction','--no-progress','--no-scripts','--no-plugins'],[0=>['pipe','r'],1=>['file',$log,'ab'],2=>['file',$log,'ab']],$pipes,$root,$environment,['bypass_shell'=>true]);
        if(!is_resource($process)){throw new \RuntimeException('Composer is required on the build machine.');}
        fclose($pipes[0]);
        if(proc_close($process)!==0){throw new \RuntimeException('Composer install failed; check the required PHP extensions and local runtime repository.');}
    }

    public static function verifyRuntime(string $runtime):void
    {
        $marker=self::file($runtime,'corevia-package.json');
        if(!is_file($marker)||filesize($marker)>4194304){throw new \LogicException('A bounded runtime inventory is required; migrate older prototypes manually.');}
        $inventory=json_decode(file_get_contents($marker),true,64,JSON_THROW_ON_ERROR);
        if(($inventory['format']??null)!==1||!is_array($inventory['files']??null)||count($inventory['files'])>10000
            ||!isset($inventory['files']['composer.json'],$inventory['files']['starters/application/skeleton/autoload.php'])){throw new \LogicException('Invalid runtime package inventory.');}
        foreach($inventory['files'] as $name=>$hash){
            if(!is_string($name)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){throw new \LogicException('Invalid runtime inventory entry.');}
            $file=self::file($runtime,$name);
            if(!is_file($file)||!hash_equals($hash,hash_file('sha256',$file))){throw new \LogicException('Runtime package was customized; migrate the fork manually.');}
        }
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runtime,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink()){throw new \LogicException('Runtime package cannot contain symlinks.');}
            $name=str_replace('\\','/',substr($file->getPathname(),strlen($runtime)+1));
            if($file->isFile()&&$name!=='corevia-package.json'&&!isset($inventory['files'][$name])){throw new \LogicException('Uninventoried runtime files require manual review.');}
        }
    }

    public static function destination(string $path):string
    {
        if(file_exists($path)||is_link($path)){throw new \LogicException('Destination must not exist.');}
        $parent=realpath(dirname($path));$name=basename($path);
        if($parent===false||!is_dir($parent)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,100}$/D',$name)||in_array($name,['.','..'],true)){throw new \InvalidArgumentException('Choose a new directory under an existing parent.');}
        return $parent.DIRECTORY_SEPARATOR.$name;
    }
    public static function file(string $root,string $relative):string
    {
        if(!preg_match('/^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/D',$relative)||array_intersect(explode('/',$relative),['.','..'])){throw new \InvalidArgumentException('Unsafe package path.');}
        $cursor=$root;foreach(explode('/',$relative) as $part){$cursor.='/'.$part;if(is_link($cursor)){throw new \LogicException('Package operations cannot follow symlinks.');}}
        return $cursor;
    }
    public static function write(string $root,string $relative,string $bytes):void
    {
        $file=self::file($root,$relative);if(!is_dir(dirname($file))&&!mkdir(dirname($file),0700,true)){throw new \RuntimeException('Cannot create application path.');}
        $handle=@fopen($file,'xb');if($handle===false){throw new \LogicException('Refusing to overwrite an application file.');}
        try{if(fwrite($handle,$bytes)!==strlen($bytes)){throw new \RuntimeException('Cannot write application file.');}}finally{fclose($handle);}
    }
}
