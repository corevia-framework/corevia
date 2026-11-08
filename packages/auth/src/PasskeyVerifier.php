<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Implementations must cryptographically verify the complete ceremony, including exact origin and UV. */
interface PasskeyVerifier
{
    public function registrationOptions(array $user,string $challenge,array $excluded):array;
    public function requestOptions(string $challenge):array;
    public function register(array $credential,array $options):array;
    public function verify(array $credential,array $options,array $source):array;
}
