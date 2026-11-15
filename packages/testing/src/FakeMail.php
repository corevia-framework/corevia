<?php
declare(strict_types=1);
namespace Corevia\Testing;
use Corevia\Auth\{AccountDelivery,AccountMessage};
final class FakeMail implements AccountDelivery
{
    private array $messages=[];
    public function __construct(){Guard::development();}
    public function deliver(AccountMessage $message):void{$this->messages[]=$message;}
    public function messages():array{return $this->messages;}
    public function assertSent(string $purpose,int $count=1):void
    {
        if(count(array_filter($this->messages,static fn($message)=>$message->purpose===$purpose))!==$count){throw new \RuntimeException('Unexpected fake mail count.');}
    }
}
