<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\ConfigRepository;

final class DocumentRules
{
    public static function collection(string $collection): void { if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$collection)) { throw new \InvalidArgumentException('Invalid collection.'); } }
    public static function id(string $id): void { if ($id==='' || strlen($id)>128 || str_contains($id,"\0")) { throw new \InvalidArgumentException('Invalid document ID.'); } }
    public static function document(array $document): void
    {
        new ConfigRepository($document);
        $walk=function(array $data) use (&$walk):void { foreach($data as $key=>$value){if(is_string($key) && (str_starts_with($key,'$') || str_contains($key,'.'))){throw new \InvalidArgumentException('Document keys cannot contain dots or MongoDB operators.');}if(is_array($value)){$walk($value);}}};
        $walk($document); if(strlen(json_encode($document,JSON_THROW_ON_ERROR))>1048576){throw new \LengthException('Document exceeds 1MiB.');}
    }
    public static function filter(array $equals,int $limit): void
    {
        if($limit<1 || $limit>1000){throw new \InvalidArgumentException('Limit must be 1..1000.');}
        foreach($equals as $key=>$value){if(!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$key) || (!is_scalar($value) && $value!==null)){throw new \InvalidArgumentException('Filters support top-level scalar equality only.');}}
    }
}
