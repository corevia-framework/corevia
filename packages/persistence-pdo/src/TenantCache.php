<?php
declare(strict_types=1);
namespace Corevia\Persistence;
/** Best-effort value cache only. Never use it for locks, quotas or authoritative data. */
final readonly class TenantCache
{
    private \Closure $clock;
    public function __construct(private TenantDocuments $documents,?callable $clock=null){$this->clock=\Closure::fromCallable($clock??time(...));}
    private function id(string $key):string{if($key===''||strlen($key)>512){throw new \InvalidArgumentException('Invalid cache key.');}return hash('sha256',$key);}
    public function put(string $key,array $value,int $seconds=60):void
    {
        if($seconds<1||$seconds>86400){throw new \InvalidArgumentException('Cache lifetime must be 1..86400 seconds.');}
        $this->documents->put('cache',$this->id($key),['expires'=>($this->clock)()+$seconds,'value'=>$value]);
    }
    public function get(string $key):?array{$row=$this->documents->get('cache',$this->id($key));return $row!==null&&($row['expires']??0)>($this->clock)()?$row['value']:null;}
    public function forget(string $key):void{$this->documents->delete('cache',$this->id($key));}
}
