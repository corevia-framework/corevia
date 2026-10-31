<?php
declare(strict_types=1);
namespace Corevia\Auth;
interface IdentityStore extends AccountStore
{
    public function state(string $key):array;
    /** Transient state callback under the store lock; user:/oid: namespaces are reserved. */
    public function changeState(string $key,callable $work):mixed;
    /** Callback receives mutable user/security arrays and returns IdentityChange. */
    public function changeIdentity(string $id,int $version,callable $work):array;
    public function sessionsFor(string $id,int $now):array;
    public function revokeSession(string $id,string $digest):void;
    /** Atomically bind/unbind a provider subject, bump credentials and revoke sessions/tokens. */
    public function changeProvider(string $id,int $version,string $provider,?string $subjectHash):array;
    /** Atomic current credential/session/security snapshot, or null for a revoked session. */
    public function authority(string $id,string $digest,int $now):?array;
}
