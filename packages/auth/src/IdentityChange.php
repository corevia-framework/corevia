<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Internal transaction result; credential changes force version/session invalidation. */
final readonly class IdentityChange
{
    public function __construct(public mixed $value=null,public bool $revoke=false,public bool $delete=false){}
}
