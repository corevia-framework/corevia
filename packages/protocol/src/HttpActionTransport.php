<?php
declare(strict_types=1);
namespace Corevia\Protocol;
use Corevia\Core\{ActionResult,ActionTransport};

/** Narrow transport port without changing the richer V3 RemoteAction API. */
final readonly class HttpActionTransport implements ActionTransport
{
    public function __construct(private RemoteAction $remote) {}
    public function call(string $target,array $payload):ActionResult { return $this->remote->call($target,$payload); }
}
