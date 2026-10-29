<?php
declare(strict_types=1);
namespace Corevia\Auth;

/** Atomic account changes shared by JSON/SQL and future adapters. */
interface AccountStore extends AuthStore
{
    /** Compare credential version and expected recipient under the same lock as issuance. */
    public function issueAccountToken(string $userId,string $purpose,string $digest,int $expires,int $userVersion,int $now,?string $expectedEmail=null):bool;
    public function accountToken(string $digest,string $purpose,int $now):?array;
    /** Reset revokes every session/token; verification requires the matching account. */
    public function completeAccountToken(string $digest,string $purpose,int $now,?string $passwordHash=null,?string $requiredUserId=null):bool;
}
