<?php
declare(strict_types=1);
namespace Corevia\Cli;

/** Stage a replacement application; the original code, data and keys remain the rollback copy. */
final class ApplicationUpgrade
{
    public static function stage(string $source,string $destination,string $runtimePath,bool $offline=false,bool $preview=false):array
    {
        $root=realpath($source);$runtime=realpath($runtimePath);
        if($root===false||$runtime===false||!is_file($root.'/corevia')||!is_file($root.'/autoload.php')||!is_file($root.'/composer.json')){throw new \InvalidArgumentException('Choose an existing application and built runtime.');}
        $target=ApplicationInstaller::destination($destination);
        $normalize=static fn(string $path):string=>(PHP_OS_FAMILY==='Windows'?strtolower(str_replace('\\','/',$path)):str_replace('\\','/',$path)).'/';
        if(str_starts_with($normalize($target),$normalize($root))){throw new \LogicException('Stage the replacement outside the source application.');}
        $legacy=is_dir($root.'/packages/core/src');
        $baseline=json_decode(file_get_contents($runtime.'/v3-compatibility.json'),true,64,JSON_THROW_ON_ERROR);
        if($legacy){
            $actual=[];
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/packages',\FilesystemIterator::SKIP_DOTS)) as $file){
                if($file->isLink()||!$file->isFile()){throw new \LogicException('Unsafe legacy framework member.');}
                $name=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
                $actual[$name]=hash('sha256',str_replace("\r\n","\n",file_get_contents($file->getPathname())));
            }
            ksort($actual,SORT_STRING);$expected=$baseline['packages'];ksort($expected,SORT_STRING);
            if($actual!==$expected||hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/autoload.php')))!==$baseline['autoload']){
                throw new \LogicException('Legacy framework or loader was customized; a manual migration is required.');
            }
        }else{
            ApplicationInstaller::verifyRuntime($root.'/vendor/corevia-framework/runtime');
            $old=$root.'/vendor/corevia-framework/runtime/starters/application/skeleton/autoload.php';
            if(!is_file($old)||file_get_contents($root.'/autoload.php')!==file_get_contents($old)){
                throw new \LogicException('Application loader was customized; merge it explicitly before staging.');
            }
        }
        $files=self::inventory($root,$legacy);
        $report=['format'=>1,'mode'=>'staged_replacement','legacy_v3'=>$legacy,'source_modified'=>false,
            'files'=>count($files),'preview'=>$preview,'external_databases_backed_up'=>false,
            'rollback'=>'Retain the original offline application and restore its matching external database backup before rollback.'];
        if($preview){return $report;}
        if(!$offline){throw new \LogicException('Declare --offline after stopping writers and backing up external databases.');}
        // A complete fresh runtime install is checked before any application-owned files are copied.
        ApplicationInstaller::create($target,['web'],'bootstrap','json',false,$runtime);
        try{
            foreach($files as $relative=>$hash){
                if(in_array($relative,['autoload.php','composer.json','composer.lock'],true)){continue;}
                $from=ApplicationInstaller::file($root,$relative);$to=ApplicationInstaller::file($target,$relative);
                if(!is_dir(dirname($to))){mkdir(dirname($to),0700,true);}
                if(file_put_contents($to,file_get_contents($from))===false||!chmod($to,0600)){throw new \RuntimeException('Cannot copy staged application file.');}
            }
            $composer=json_decode(file_get_contents($root.'/composer.json'),true,64,JSON_THROW_ON_ERROR);
            $composer['name']=($composer['name']??'corevia-app/upgraded')==='corevia-framework/corevia'?'corevia-app/upgraded':($composer['name']??'corevia-app/upgraded');
            $library=json_decode(file_get_contents($runtime.'/composer.json'),true,64,JSON_THROW_ON_ERROR);
            foreach(array_keys($library['autoload']['psr-4']) as $prefix){unset($composer['autoload']['psr-4'][$prefix]);}
            unset($composer['require']['corevia-framework/core'],$composer['require']['corevia-framework/corevia']);
            $composer['require']['corevia-framework/runtime']=\Corevia\Core\Version::VERSION;
            $repositories=$composer['repositories']??[];
            foreach($repositories as $key=>$repository){if(is_array($repository)&&isset($repository['options']['versions']['corevia-framework/runtime'])){unset($repositories[$key]);}}
            $composer['repositories']=['corevia-runtime'=>['type'=>'path','url'=>str_replace('\\','/',$runtime),'options'=>['symlink'=>false,'versions'=>['corevia-framework/runtime'=>\Corevia\Core\Version::VERSION]]]]+$repositories;
            $composer['config']['allow-plugins']=false;
            file_put_contents($target.'/composer.json',json_encode($composer,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
            // Carry forward unrelated locked dependencies; update only the selected runtime and its closure.
            if(is_file($target.'/composer.lock')){unlink($target.'/composer.lock');}
            $hasLock=is_file($root.'/composer.lock');
            if($hasLock){copy($root.'/composer.lock',$target.'/composer.lock');}
            ApplicationInstaller::composer($target,$hasLock);
            if(self::inventory($root,$legacy)!==$files){throw new \LogicException('Source changed while staging; discard the replacement and retry offline.');}
            $receipt=['format'=>1,'source_files'=>$files,'legacy_v3'=>$legacy,'external_databases_backed_up'=>false];
            $directory=$target.'/storage/.corevia-upgrade-'.bin2hex(random_bytes(8));
            if(!mkdir($directory,0700)){throw new \RuntimeException('Cannot reserve upgrade receipt storage.');}
            // Compiled caches can contain absolute paths into the original installation.
            // Preserve them privately for review, but require recompilation in the replacement.
            $invalidated=[];
            foreach(array_merge(glob($target.'/storage/config-*.json')?:[],[$target.'/storage/application.build.json']) as $cached){
                if(!is_file($cached)){continue;}
                if(filesize($cached)>4194304){throw new \LengthException('Review the oversized staged cache manually.');}
                $document=json_decode(file_get_contents($cached),true);
                $config=is_array($document)&&($document['format']??null)===1&&isset($document['environment'],$document['values']);
                $artifact=is_array($document)&&($document['payload']['format']??null)===1&&isset($document['fingerprint']);
                if($config||$artifact){
                    $backup=$directory.'/'.basename($cached);if(!rename($cached,$backup)){throw new \RuntimeException('Cannot quarantine stale compiled material.');}
                    chmod($backup,0600);$invalidated[]=basename($cached);
                }
            }
            $receipt['invalidated_compiled_material']=$invalidated;
            file_put_contents($directory.'/receipt.json',json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));chmod($directory.'/receipt.json',0600);
            return $report+['staged'=>true,'application'=>$target,'invalidated_compiled_material'=>$invalidated,'configuration_review_required'=>true];
        }catch(\Throwable $error){throw new \RuntimeException('Staging failed; retain the original application and inspect the separate replacement.',0,$error);}
    }

    private static function inventory(string $root,bool $legacy):array
    {
        $source=new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS);
        $filter=new \RecursiveCallbackFilterIterator($source,static function(\SplFileInfo $file)use($root,$legacy):bool{
            if($file->isLink()){throw new \LogicException('Upgrade snapshots cannot follow symlinks.');}
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            if($file->isDir()&&in_array($file->getFilename(),['.git','node_modules','.dart_tool','.codex','.agents','local-v3-acceptance'],true)){return false;}
            return $relative!=='vendor'&&(!$legacy||$relative!=='packages');
        });
        $files=[];$total=0;
        foreach(new \RecursiveIteratorIterator($filter) as $file){
            if(!$file->isFile()){continue;}$total+=$file->getSize();
            if($total>536870912){throw new \LengthException('Upgrade snapshot exceeds 512MiB; use an operator-managed backup/migration.');}
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            ApplicationInstaller::file($root,$relative);$files[$relative]=hash_file('sha256',$file->getPathname());
        }
        ksort($files,SORT_STRING);return $files;
    }
}
