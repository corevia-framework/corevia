<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Construct only from a resource loaded by trusted server code. */
final readonly class ResourceIdentity
{
    public function __construct(public ?string $tenant=null,public ?string $owner=null){}
}
