<?php
declare(strict_types=1);
namespace Corevia\Protocol;

final readonly class ContractClient
{
    private \Closure $transport;
    public function __construct(public ContractManifest $manifest, callable $transport) { $this->transport = \Closure::fromCallable($transport); }
    public function action(string $name, mixed $payload): mixed
    {
        $this->manifest->assertInput($name, $payload); $result = ($this->transport)($name, $payload, $this->manifest->requirements($name));
        $this->manifest->assertOutput($name, $result); return $result;
    }
}
