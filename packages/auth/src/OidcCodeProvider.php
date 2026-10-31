<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{HttpClient,HttpJson};
/** Explicit pinned metadata. No user-controlled discovery, redirect or JWKS endpoints. */
final readonly class OidcCodeProvider implements OidcProvider
{
    public function __construct(private HttpClient $http,private string $id,private string $secret,private string $issuer,private string $authorize,private string $token,private string $jwks)
    {foreach([$issuer,$authorize,$token,$jwks] as $url){$parts=parse_url($url);if(($parts['scheme']??null)!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['fragment'])){throw new \InvalidArgumentException('OIDC metadata must use pinned HTTPS endpoints.');}}}
    public static function google(HttpClient $http,string $id,string $secret):self{return new self($http,$id,$secret,'https://accounts.google.com','https://accounts.google.com/o/oauth2/v2/auth','https://oauth2.googleapis.com/token','https://www.googleapis.com/oauth2/v3/certs');}
    public function authorizationEndpoint():string{return $this->authorize;}
    public function clientId():string{return $this->id;}
    public function scopes():array{return ['openid','profile','email'];}
    public function identity(string $code,string $verifier,string $redirectUri):string{throw new \LogicException('OIDC requires a session-bound nonce.');}
    public function identityWithNonce(string $code,string $verifier,string $redirectUri,string $nonce):string
    {
        $response=HttpJson::request($this->http,'POST',$this->token,['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'authorization_code','client_id'=>$this->id,'client_secret'=>$this->secret,'code'=>$code,'code_verifier'=>$verifier,'redirect_uri'=>$redirectUri]));
        if(!is_string($response['id_token']??null)){throw new \RuntimeException('Provider returned no ID token.');}$jwks=HttpJson::request($this->http,'GET',$this->jwks);if(count($jwks['keys']??[])>100){throw new \LengthException('OIDC key-set exceeds its bound.');}return IdTokenVerifier::verify($response['id_token'],$jwks,$this->issuer,$this->id,$nonce)['sub'];
    }
}
