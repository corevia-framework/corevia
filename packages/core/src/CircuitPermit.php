<?php
declare(strict_types=1);
namespace Corevia\Core;

/** In-process completion ticket. Never serialize or share between requests. */
final class CircuitPermit
{
    private bool $consumed=false;
    public function __construct(public readonly string $resource,public readonly int $generation,private readonly string $probe='') {}
    private function __clone() {}
    public function __serialize():array { throw new \LogicException('Circuit permits cannot be serialized.'); }
    public function consume(string $resource):string
    {
        if ($this->consumed||!hash_equals($this->resource,$resource)) { throw new \LogicException('Invalid or already completed circuit permit.'); }
        $this->consumed=true;return $this->probe;
    }
}
