<?php
declare(strict_types=1);
namespace Corevia\Auth;
final readonly class AuthContext
{
    public function __construct(public string $digest,public string $csrf,public ?array $user,private ?string $cookie){}
    public function cookie():?string{return $this->cookie;}
}
