<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** The bearer link is excluded from default JSON/public-property serialization. */
final readonly class AccountMessage
{
    public function __construct(public string $recipient,public string $purpose,public int $expires,private string $link){}
    public function link():string{return $this->link;}
    public function subject():string{return $this->purpose==='reset'?'Reset your password':'Verify your email address';}
    public function text():string{return $this->subject()."\n\nOpen this link to continue:\n".$this->link."\n\nIf you did not request this, ignore this message.";}
}
