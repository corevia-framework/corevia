<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** RS256 only; pinned issuer/client, trusted JWKS, no remote token header URLs. */
final class IdTokenVerifier
{
    private static function bytes(string $text):string{if($text===''||!preg_match('/^[A-Za-z0-9_-]+$/D',$text)){throw new \RuntimeException('Invalid JWT encoding.');}$bytes=base64_decode(strtr($text,'-_','+/').str_repeat('=',(4-strlen($text)%4)%4),true);if($bytes===false){throw new \RuntimeException('Invalid JWT encoding.');}return $bytes;}
    private static function der(int $tag,string $body):string{$length=strlen($body);$prefix=$length<128?chr($length):(($length<256?"\x81":"\x82").($length<256?chr($length):pack('n',$length)));return chr($tag).$prefix.$body;}
    public static function verify(string $token,array $jwks,string $issuer,string $client,string $nonce,?int $now=null):array
    {
        if(!function_exists('openssl_verify')||strlen($token)>16384||$nonce===''){throw new \RuntimeException('ID-token verification unavailable or invalid.');}$parts=explode('.',$token);if(count($parts)!==3){throw new \RuntimeException('Invalid ID token.');}
        $header=json_decode(self::bytes($parts[0]),true,16,JSON_THROW_ON_ERROR);$claims=json_decode(self::bytes($parts[1]),true,32,JSON_THROW_ON_ERROR);
        if(($header['alg']??null)!=='RS256'||!is_string($header['kid']??null)||isset($header['crit'])||!is_array($claims)){throw new \RuntimeException('Unsupported ID-token header.');}$matches=[];
        foreach($jwks['keys']??[] as $key){if(($key['kid']??null)===$header['kid']&&($key['kty']??null)==='RSA'&&($key['use']??'sig')==='sig'&&($key['alg']??'RS256')==='RS256'){$matches[]=$key;}}if(count($matches)!==1){throw new \RuntimeException('ID-token signing key is unavailable or ambiguous.');}$key=$matches[0];
        $integer=static function(string $bytes):string{$bytes=ltrim($bytes,"\0");if($bytes===''||ord($bytes[0])>127){$bytes="\0".$bytes;}return self::der(2,$bytes);};$n=self::bytes($key['n']??'');$e=self::bytes($key['e']??'');if(strlen($n)<256||strlen($n)>1024||strlen($e)>8){throw new \RuntimeException('Invalid RSA signing key.');}
        $rsa=self::der(48,$integer($n).$integer($e));$spki=self::der(48,hex2bin('300d06092a864886f70d0101010500').self::der(3,"\0".$rsa));$pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
        if(openssl_verify($parts[0].'.'.$parts[1],self::bytes($parts[2]),$pem,OPENSSL_ALGO_SHA256)!==1){throw new \RuntimeException('Invalid ID-token signature.');}$now??=time();$aud=$claims['aud']??null;$aud=is_string($aud)?[$aud]:$aud;
        if(($claims['iss']??null)!==$issuer||!is_array($aud)||!in_array($client,$aud,true)||(isset($claims['azp'])&&$claims['azp']!==$client)||(count($aud)>1&&($claims['azp']??null)!==$client)||!is_int($claims['exp']??null)||$claims['exp']<=$now||!is_int($claims['iat']??null)||$claims['iat']>$now+60||(isset($claims['nbf'])&&(!is_int($claims['nbf'])||$claims['nbf']>$now+60))||!is_string($claims['nonce']??null)||!hash_equals($nonce,$claims['nonce'])||!is_string($claims['sub']??null)||$claims['sub']===''||strlen($claims['sub'])>512){throw new \RuntimeException('ID-token claims were rejected.');}return $claims;
    }
}
