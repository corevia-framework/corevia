<?php
declare(strict_types=1);
namespace Corevia\Http;

/** Signed, expiring cookie plus a separate form token; no session extension required. */
final readonly class Csrf implements Middleware
{
    public function __construct(private string $secret,private bool $secure=false)
    {
        if(strlen($secret)<32){throw new \InvalidArgumentException('CSRF signing key must have at least 32 bytes.');}
    }
    public function process(Request $request,callable $next): Response
    {
        $cookie=$request->cookie('corevia_csrf');$valid=false;
        if(is_string($cookie) && preg_match('/^([0-9]{10})\.([a-f0-9]{64})\.([a-f0-9]{64})$/D',$cookie,$parts)){
            $age=time()-(int)$parts[1];$valid=$age>=0 && $age<=7200 && hash_equals(hash_hmac('sha256',$parts[1].'.'.$parts[2],$this->secret),$parts[3]);
        }
        if(!in_array($request->method,['GET','HEAD','OPTIONS'],true)){
            $input=$request->input();$token=$request->header('x-csrf-token') ?: ($input['_token'] ?? null);
            if(!$valid || !is_string($token) || !hash_equals(hash_hmac('sha256','form|'.$cookie,$this->secret),$token)){return Response::html('<h1>Form expired</h1><p>Reload the page and submit again.</p>',419);}
        }
        $fresh=!$valid;
        if($fresh){$value=time().'.'.bin2hex(random_bytes(32));$cookie=$value.'.'.hash_hmac('sha256',$value,$this->secret);}
        $response=$next($request->withAttribute('csrf_token',hash_hmac('sha256','form|'.$cookie,$this->secret)));
        return $fresh?$response->withHeaders(['Set-Cookie'=>'corevia_csrf='.rawurlencode($cookie).'; Path=/; Max-Age=7200; HttpOnly; SameSite=Lax'.($this->secure?'; Secure':'')]):$response;
    }
}
