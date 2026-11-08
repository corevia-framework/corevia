<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Called outside identity-store transactions. Throw to refuse linking or session creation. */
interface OAuthLoginPolicy
{
    public function validateProviders(array $providers):void;
    public function validate(string $provider,array $user,bool $linking):void;
    public function authenticated(string $provider,AuthContext $context):void;
}
