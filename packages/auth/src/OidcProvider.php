<?php
declare(strict_types=1);
namespace Corevia\Auth;
interface OidcProvider extends OAuthProvider
{
    public function identityWithNonce(string $code,string $verifier,string $redirectUri,string $nonce):string;
}
