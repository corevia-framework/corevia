<?php
declare(strict_types=1);
namespace Corevia\Http;

/** Pure signature codec: caller owns HTTPS delivery, endpoint policy and atomic replay storage. */
final readonly class WebhookSignature
{
    public function __construct(private array $keys,private string $active)
    {
        if(!isset($keys[$active])||count($keys)<1||count($keys)>8){throw new \InvalidArgumentException('Invalid webhook key ring.');}
        foreach($keys as $id=>$key){if(!is_string($id)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/D',$id)||!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Use named independent webhook secrets of at least 32 bytes.');}}
    }
    public function sign(string $body,string $eventId,int $now):string
    {
        $this->input($body,$eventId);return $this->active.'.'.$now.'.'.$eventId.'.'.hash_hmac('sha256',$now.'.'.$eventId.'.'.$body,$this->keys[$this->active]);
    }
    /** consumeReplay(digest,expires) must atomically return true only for the first reservation. */
    public function verify(string $body,string $header,int $now,callable $consumeReplay,int $tolerance=300):string
    {
        if($tolerance<1||$tolerance>900||strlen($header)>256){throw new \InvalidArgumentException('Invalid webhook verification bounds.');}
        if(!preg_match('/^([a-zA-Z][a-zA-Z0-9_-]{0,31})\.([0-9]{1,12})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$header,$parts)){throw new \RuntimeException('Invalid webhook signature.');}
        [, $keyId,$issued,$id,$signature]=$parts;$this->input($body,$id);
        if(!isset($this->keys[$keyId])||abs($now-(int)$issued)>$tolerance||!hash_equals(hash_hmac('sha256',$issued.'.'.$id.'.'.$body,$this->keys[$keyId]),$signature)){throw new \RuntimeException('Invalid webhook signature.');}
        if($consumeReplay(hash('sha256',$id),((int)$issued)+$tolerance+1)!==true){throw new \RuntimeException('Webhook replay rejected.');}return $id;
    }
    private function input(string $body,string $id):void{if(strlen($body)>1048576||!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new \InvalidArgumentException('Use a bounded raw body and opaque event ID.');}}
}
