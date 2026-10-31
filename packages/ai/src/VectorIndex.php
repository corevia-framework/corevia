<?php
declare(strict_types=1);
namespace Corevia\Ai;
interface VectorIndex
{
    public function put(string $tenant,string $id,string $text,array $vector):void;
    public function search(string $tenant,array $vector,int $limit=5):array;
    public function delete(string $tenant,string $id):void;
}
