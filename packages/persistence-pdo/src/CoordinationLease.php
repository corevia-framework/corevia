<?php
declare(strict_types=1);
namespace Corevia\Persistence;
final readonly class CoordinationLease
{
    public function __construct(public string $key,public int $fence,private string $owner){}
    public function owner():string{return $this->owner;}
}
