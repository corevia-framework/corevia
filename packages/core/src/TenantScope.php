<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Server-owned capability. Never construct from an unverified header or request body. */
final readonly class TenantScope
{
    private \Closure $authority;
    public function __construct(public string $tenant,public string $actor,callable $authority,private array $abilities=[])
    {
        foreach([$tenant,$actor] as $id){if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$id)){throw new \InvalidArgumentException('Invalid tenant/actor identity.');}}
        if(!array_is_list($abilities)||count($abilities)>64){throw new \InvalidArgumentException('Use a bounded capability list.');}
        foreach($abilities as $ability){if(!is_string($ability)||!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$ability)){throw new \InvalidArgumentException('Use explicit capabilities without wildcards.');}}
        $this->authority=\Closure::fromCallable($authority);$this->check();
    }
    public function check():void{if(($this->authority)()!==true){throw new \RuntimeException('Tenant authority expired or was revoked.');}}
    public function requireAbility(string $ability):void{$this->check();if(!in_array($ability,$this->abilities,true)){throw new \RuntimeException('Tenant capability is not granted.');}}
    public function namespace(string $purpose):string
    {
        $this->check();if($purpose===''||strlen($purpose)>256){throw new \InvalidArgumentException('Invalid tenant namespace purpose.');}
        return hash('sha256',json_encode([$this->tenant,$purpose],JSON_THROW_ON_ERROR));
    }
    public function __serialize():array{throw new \LogicException('Resolve tenant authority again in each execution scope.');}
}
