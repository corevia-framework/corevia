<?php
declare(strict_types=1);
namespace Corevia\Http;
interface Disk
{
    public function put(string $key,string $bytes):void;
    public function get(string $key):string;
    public function delete(string $key):void;
    public function exists(string $key):bool;
}
