<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Versioned AES-256-GCM envelopes with purpose/user binding. Requires OpenSSL. */
final readonly class SecretBox
{
    public function __construct(private string $key){if(!function_exists('openssl_encrypt') || strlen($key)!==32){throw new \LogicException('Encrypted auth features require OpenSSL and a 32-byte key.');}}
    public static function fromFile(string $path):self
    {
        if(!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true) && !is_dir(dirname($path))){throw new \RuntimeException('Cannot create private key directory.');}
        if(is_link($path)||is_link($path.'.lock')||is_link(dirname($path))){throw new \LogicException('Auth keys cannot use symlinks.');}
        $lock=fopen($path.'.lock','c+b');if($lock===false || !chmod($path.'.lock',0600)||!flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock auth key.');}
        try{if(!is_file($path)){$file=@fopen($path,'x+b');if($file===false){throw new \RuntimeException('Cannot create auth key.');}try{if(!chmod($path,0600)||fwrite($file,random_bytes(32))!==32){throw new \RuntimeException('Cannot persist auth encryption key.');}}finally{fclose($file);}}
        $key=file_get_contents($path);if($key===false){throw new \RuntimeException('Cannot read auth encryption key.');}return new self($key);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function seal(string $value,string $purpose):string{$iv=random_bytes(12);$tag='';$data=openssl_encrypt($value,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$purpose,16);if($data===false){throw new \RuntimeException('Cannot encrypt auth data.');}return 'gcm1.'.base64_encode($iv.$tag.$data);}
    public function open(string $envelope,string $purpose):string{if(!str_starts_with($envelope,'gcm1.')){throw new \RuntimeException('Unknown auth encryption format.');}$bytes=base64_decode(substr($envelope,5),true);if($bytes===false || strlen($bytes)<28){throw new \RuntimeException('Invalid encrypted auth data.');}$value=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),$purpose);if($value===false){throw new \RuntimeException('Auth data cannot be decrypted with this key.');}return $value;}
}
