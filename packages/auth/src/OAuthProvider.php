<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Trusted application adapter. Exchange over verified HTTPS; require provider PKCE S256 support.
 * Validate token/userinfo responses (including issuer/signature/audience/nonce when using OIDC).
 * Return the stable provider subject, never an email address used as an account identifier.
 */
interface OAuthProvider
{
    public function authorizationEndpoint():string;
    public function clientId():string;
    public function scopes():array;
    public function identity(string $code,string $verifier,string $redirectUri):string;
}
