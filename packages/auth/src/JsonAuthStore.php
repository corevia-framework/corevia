<?php
declare(strict_types=1);
namespace Corevia\Auth;

/** Single-node reference store; separate lock plus private atomic replacement. */
final readonly class JsonAuthStore extends AtomicAuthStore
{
    public function __construct(private string $path){}
    protected function transaction(callable $work,?int $now=null):mixed
    {
        $directory=dirname($this->path);
        if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)){throw new \RuntimeException('Cannot create private auth storage.');}
        if(is_link($this->path) || is_link($this->path.'.lock')){throw new \RuntimeException('Auth storage cannot use symlinks.');}
        $lock=fopen($this->path.'.lock','c+b');if($lock===false || !chmod($this->path.'.lock',0600) || !flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock auth storage.');}
        try{
            $data=['format'=>1,'users'=>[],'sessions'=>[],'limits'=>[]];
            if(is_file($this->path)){
                clearstatcache(true,$this->path);if(filesize($this->path)>16777216){throw new \RuntimeException('Auth storage exceeds its single-node size limit.');}
                $data=json_decode(file_get_contents($this->path),true,64,JSON_THROW_ON_ERROR);
                if(!is_array($data) || ($data['format']??null)!==1){throw new \RuntimeException('Invalid auth storage format.');}
                foreach(['users','sessions','limits'] as $key){if(!isset($data[$key]) || !is_array($data[$key])){throw new \RuntimeException('Invalid auth records.');}}
            }
            $data['tokens']??=[];if(!is_array($data['tokens'])){throw new \RuntimeException('Invalid account token records.');}
            $data['state']??=[];if(!is_array($data['state'])){throw new \RuntimeException('Invalid identity state.');}
            foreach($data['state'] as $key=>$state){if(isset($state['expires']) && $state['expires']<=($now??time())){unset($data['state'][$key]);}}
            if($now!==null){foreach(['sessions','limits','tokens'] as $key){foreach($data[$key] as $id=>$record){if(!is_array($record) || !is_int($record['expires']??null)){throw new \RuntimeException('Invalid auth expiry record.');}if($record['expires']<=$now){unset($data[$key][$id]);}}}}
            $result=$work($data);$wire=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(strlen($wire)>16777216){throw new \RuntimeException('Auth storage exceeds its single-node size limit.');}
            $temporary=$this->path.'.'.bin2hex(random_bytes(8)).'.tmp';$file=fopen($temporary,'x+b');if($file===false){throw new \RuntimeException('Cannot create auth records.');}
            try{try{if(!chmod($temporary,0600) || fwrite($file,$wire)!==strlen($wire) || !fflush($file)){throw new \RuntimeException('Cannot persist auth records.');}}finally{fclose($file);}if(!rename($temporary,$this->path)){throw new \RuntimeException('Cannot replace auth records.');}}
            finally{if(is_file($temporary)){unlink($temporary);}}
            return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
