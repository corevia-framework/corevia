<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\TenantScope;
final readonly class TenantDisk implements Disk
{
    public function __construct(private Disk $disk,private TenantScope $scope){}
    private function key(string $key):string
    {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D',$key)){throw new \InvalidArgumentException('Use opaque file names without directories.');}
        return $this->scope->namespace('files').'/'.$key;
    }
    public function put(string $key,string $bytes):void{$this->disk->put($this->key($key),$bytes);}
    public function get(string $key):string{return $this->disk->get($this->key($key));}
    public function delete(string $key):void{$this->disk->delete($this->key($key));}
    public function exists(string $key):bool{return $this->disk->exists($this->key($key));}
}
