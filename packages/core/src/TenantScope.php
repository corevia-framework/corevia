<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Server-owned capability. Never construct from an unverified header or request body. */
final readonly class TenantScope
{
    private \Closure $authority;
    public function __construct(public string $tenant,public string $actor,callable $authority)
    {
        foreach([$tenant,$actor] as $id){if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$id)){throw new \InvalidArgumentException('Invalid tenant/actor identity.');}}
        $this->authority=\Closure::fromCallable($authority);$this->check();
    }
    public function check():void{if(($this->authority)()!==true){throw new \RuntimeException('Tenant authority expired or was revoked.');}}
    public function namespace(string $purpose):string
    {
        $this->check();if($purpose===''||strlen($purpose)>256){throw new \InvalidArgumentException('Invalid tenant namespace purpose.');}
        return hash('sha256',json_encode([$this->tenant,$purpose],JSON_THROW_ON_ERROR));
    }
    public function __serialize():array{throw new \LogicException('Resolve tenant authority again in each execution scope.');}
}
