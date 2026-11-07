<?php
declare(strict_types=1);
namespace Corevia\Http;

/** Bounded private files. Download routes must authorize callers before reading this disk. */
final readonly class LocalDisk implements Disk
{
    private string $root;
    public function __construct(string $directory,string $publicDirectory,private int $maxBytes=8388608)
    {
        if($maxBytes<1||$maxBytes>16777216||is_link($directory)){throw new \InvalidArgumentException('Invalid private disk configuration.');}
        if(!is_dir($directory)&&!mkdir($directory,0700,true)){throw new \RuntimeException('Cannot create private disk.');}
        $root=realpath($directory);$public=realpath($publicDirectory);
        $normalize=static fn(string $path):string=>(PHP_OS_FAMILY==='Windows'?strtolower(str_replace('\\','/',$path)):str_replace('\\','/',$path)).'/';
        if($root===false||$public===false||str_starts_with($normalize($root),$normalize($public))){throw new \LogicException('Private disk must be outside the public directory.');}
        $this->root=$root;
    }
    private function path(string $key):string
    {
        if(strlen($key)>240||!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$/D',$key)||count(explode('/',$key))>10){throw new \InvalidArgumentException('Invalid private file key.');}
        $path=$this->root;foreach(explode('/',$key) as $part){if(in_array($part,['.','..'],true)){throw new \InvalidArgumentException('Invalid private file key.');}$path.='/'.$part;if(is_link($path)){throw new \LogicException('Private disk cannot follow symlinks.');}}return $path;
    }
    public function put(string $key,string $bytes):void
    {
        if(strlen($bytes)>$this->maxBytes){throw new \LengthException('File exceeds private disk limit.');}$path=$this->path($key);
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)){throw new \RuntimeException('Cannot create private file directory.');}
        $file=@fopen($path,'xb');if($file===false){throw new \LogicException('Refusing to overwrite private file.');}
        try{if(!chmod($path,0600)||fwrite($file,$bytes)!==strlen($bytes)){throw new \RuntimeException('Cannot persist private file.');}}catch(\Throwable $error){fclose($file);unlink($path);throw $error;}fclose($file);
    }
    public function get(string $key):string
    {
        $path=$this->path($key);if(!is_file($path)||filesize($path)>$this->maxBytes){throw new \RuntimeException('Private file unavailable.');}
        $data=file_get_contents($path,false,null,0,$this->maxBytes+1);if($data===false||strlen($data)>$this->maxBytes){throw new \RuntimeException('Cannot read bounded private file.');}return $data;
    }
    public function delete(string $key):void{$path=$this->path($key);if(is_file($path)&&!unlink($path)){throw new \RuntimeException('Cannot remove private file.');}}
    public function exists(string $key):bool{return is_file($this->path($key));}
    public function upload(array $upload,array $allowedMimes):array
    {
        if(($upload['error']??null)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])){throw new \InvalidArgumentException('Use an actual successful PHP upload.');}
        if(!class_exists(\finfo::class)){throw new \LogicException('Fileinfo is required for upload validation.');}
        $size=filesize($upload['tmp_name']);if($size===false||$size>$this->maxBytes){throw new \LengthException('Upload exceeds private disk limit.');}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);if(!in_array($mime,$allowedMimes,true)){throw new \InvalidArgumentException('Upload type is not allowed.');}
        $key=bin2hex(random_bytes(16)).'.bin';$this->put($key,file_get_contents($upload['tmp_name']));return ['key'=>$key,'mime'=>$mime,'bytes'=>$size];
    }
}
