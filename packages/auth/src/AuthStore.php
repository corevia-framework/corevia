<?php
declare(strict_types=1);
namespace Corevia\Auth;
interface AuthStore
{
    public function findUser(string $email):?array;
    public function userById(string $id):?array;
    /** Null means the normalized email already exists; uniqueness must be atomic. */
    public function createUser(string $email,string $name,string $passwordHash):?array;
    /** Check user_version against the current account atomically before saving authenticated sessions. */
    public function saveSession(string $digest,array $session,int $now):void;
    public function session(string $digest,int $now):?array;
    public function deleteSession(string $digest):void;
    /** Atomically consume all [key, limit, window-seconds] rules or none. */
    public function consume(array $rules,int $now):bool;
}
