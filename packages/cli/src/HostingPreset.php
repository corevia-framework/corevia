<?php
declare(strict_types=1);
namespace Corevia\Cli;

use Corevia\Core\FrameworkResources;

/** Emits reviewable recipes only: never installs services or contacts a cloud account. */
final class HostingPreset
{
    public static function prepare(string $application,string $target,string $output,bool $dryRun=false):array
    {
        if(!in_array($target,['shared','vps','aws','azure'],true)){throw new \InvalidArgumentException('Unknown hosting target.');}
        if(!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D',$output)||strlen($output)>160){throw new \InvalidArgumentException('Output must be a relative directory inside application storage.');}
        $base=realpath($application);
        if($base===false||!is_dir($base)){throw new \InvalidArgumentException('Application directory is missing.');}
        $storage=$base.'/storage';
        if(is_link($storage)||!is_dir($storage)||realpath($storage)===false){throw new \InvalidArgumentException('Create a private, non-symlink application storage directory first.');}
        $cursor=$storage;
        foreach(explode('/',$output)as $part){$cursor.='/'.$part;if(is_link($cursor)||file_exists($cursor)){throw new \InvalidArgumentException('Choose a new output path with no existing components below storage.');}}
        $source=FrameworkResources::root().'/packages/cli/resources/hosting';
        $files=['README.md'=>self::read($source.'/common.md')];
        $catalog=match($target){
            'shared'=>['apache.htaccess','cron.txt'],
            'vps'=>['nginx.conf','php-fpm.conf','corevia-queue.service','corevia-queue.timer'],
            'aws'=>['corevia.config','apache.htaccess'],
            'azure'=>['nginx.conf','startup.sh'],
        };
        foreach($catalog as $name){$files[$name]=self::read($source.'/'.$target.'/'.$name);}
        $hashes=[];foreach($files as $name=>$wire){$hashes[$name]=hash('sha256',$wire);}
        $report=['format'=>1,'target'=>$target,'target_validated'=>false,'production_ready'=>false,'recipe_only'=>true,'output'=>$storage.'/'.$output,'files'=>$hashes];
        if($dryRun){return $report+['written'=>false];}
        $old=umask(0077);
        try{
            if(!mkdir($cursor,0700,true)){throw new \RuntimeException('Cannot create private preset directory.');}
            foreach($files+['manifest.json'=>json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"]as $name=>$wire){
                $handle=fopen($cursor.'/'.$name,'xb');if($handle===false){throw new \RuntimeException('Cannot create preset file.');}
                try{if(fwrite($handle,$wire)!==strlen($wire)||!chmod($cursor.'/'.$name,0600)){throw new \RuntimeException('Cannot save private preset file.');}}finally{fclose($handle);}
            }
        }finally{umask($old);}
        return $report+['written'=>true];
    }
    private static function read(string $path):string
    {
        if(is_link($path)||!is_file($path)||filesize($path)>65536){throw new \RuntimeException('Hosting recipe is unavailable.');}
        $wire=file_get_contents($path);if($wire===false){throw new \RuntimeException('Cannot read hosting recipe.');}return $wire;
    }
}
