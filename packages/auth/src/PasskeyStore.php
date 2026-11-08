<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Optional atomic credential binding/CAS capability; existing third-party IdentityStores stay compatible. */
interface PasskeyStore extends IdentityStore
{
    /** null record removes; null expectedHash registers; otherwise compare/update verified assertion state. */
    public function changePasskey(string $id,int $version,string $digest,?array $record,?string $expectedHash=null):array;
}
