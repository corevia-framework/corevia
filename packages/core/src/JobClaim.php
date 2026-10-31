<?php
declare(strict_types=1);
namespace Corevia\Core;
final readonly class JobClaim
{
    public function __construct(public string $id,public string $task,public int $attempt,private array $input,private string $token){}
    public function input():array{return $this->input;}
    public function token():string{return $this->token;}
    public function idempotencyKey():string{return 'job:'.$this->id;}
}
