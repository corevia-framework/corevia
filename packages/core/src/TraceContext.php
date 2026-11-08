<?php
declare(strict_types=1);
namespace Corevia\Core;

/** W3C version-00 context. No baggage/tracestate or application data is propagated. */
final readonly class TraceContext
{
    public function __construct(public string $traceId,public string $spanId,public bool $sampled=true)
    {
        if (!preg_match('/^[0-9a-f]{32}$/D',$traceId)||$traceId===str_repeat('0',32)
            ||!preg_match('/^[0-9a-f]{16}$/D',$spanId)||$spanId===str_repeat('0',16)) { throw new \InvalidArgumentException('Invalid trace context.'); }
    }
    public static function create(bool $sampled=true):self { return new self(bin2hex(random_bytes(16)),bin2hex(random_bytes(8)),$sampled); }
    public static function parse(string $header):?self
    {
        if (!preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D',$header,$m)) { return null; }
        try { return new self($m[1],$m[2],(hexdec($m[3])&1)===1); } catch (\InvalidArgumentException) { return null; }
    }
    public function child():self { return new self($this->traceId,bin2hex(random_bytes(8)),$this->sampled); }
    public function header():string { return '00-'.$this->traceId.'-'.$this->spanId.'-'.($this->sampled?'01':'00'); }
}
