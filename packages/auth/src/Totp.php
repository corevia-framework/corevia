<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** RFC 4226/6238 HMAC-SHA1, 30-second TOTP. */
final class Totp
{
    public static function secret():string{return self::encode(random_bytes(20));}
    public static function encode(string $bytes):string{$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($bytes) as $byte){$bits.=str_pad(decbin(ord($byte)),8,'0',STR_PAD_LEFT);}$result='';foreach(str_split($bits,5) as $chunk){$result.=$alphabet[bindec(str_pad($chunk,5,'0'))];}return $result;}
    private static function decode(string $secret):string{if(!preg_match('/^[A-Z2-7]{16,128}$/D',$secret)){throw new \InvalidArgumentException('Invalid authenticator secret.');}$bits='';foreach(str_split($secret) as $char){$bits.=str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$char)),5,'0',STR_PAD_LEFT);}$result='';foreach(str_split($bits,8) as $chunk){if(strlen($chunk)===8){$result.=chr(bindec($chunk));}}return $result;}
    public static function code(string $secret,int $time,int $digits=6):string{if($time<0 || !in_array($digits,[6,8],true)){throw new \InvalidArgumentException('Invalid TOTP parameters.');}$counter=intdiv($time,30);$hash=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),self::decode($secret),true);$offset=ord($hash[19])&15;$number=unpack('N',substr($hash,$offset,4))[1]&2147483647;return str_pad((string)($number%(10**$digits)),$digits,'0',STR_PAD_LEFT);}
    public static function counter(string $secret,string $code,int $time,int $last=-1):?int{if(!preg_match('/^[0-9]{6}$/D',$code)){return null;}foreach([0,-1,1] as $step){$counter=intdiv($time,30)+$step;if($counter>=0 && $counter>$last && hash_equals(self::code($secret,$counter*30),$code)){return $counter;}}return null;}
}
