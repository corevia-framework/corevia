<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Stable map ordering, preserved list ordering, and strict finite JSON values. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        $precision=ini_get('serialize_precision');
        if($precision!=='-1' && ini_set('serialize_precision','-1')===false){throw new \RuntimeException('Canonical JSON requires stable floating point serialization.');}
        try{return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);}
        finally{if($precision!==false && $precision!=='-1'){ini_set('serialize_precision',$precision);}}
    }
    public static function hash(mixed $value): string { return hash('sha256', self::encode($value)); }
    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 60 || (is_object($value) && !$value instanceof \stdClass) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Canonical data must be finite JSON-compatible values, at most 60 levels deep.');
        }
        if($value instanceof \stdClass){$properties=get_object_vars($value);ksort($properties,SORT_STRING);foreach($properties as $key=>$item){$properties[$key]=self::normalize($item,$depth+1);}return (object)$properties;}
        if (!is_array($value)) { return $value; }
        $list = array_is_list($value);
        if (!$list) {
            ksort($value, SORT_STRING);
            if (array_is_list($value)) { throw new \InvalidArgumentException('Use an ordered list for contiguous numeric keys.'); }
        }
        foreach ($value as $key => $item) { $value[$key] = self::normalize($item, $depth + 1); }
        return $list ? $value : (object) $value;
    }
}
