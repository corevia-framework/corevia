<?php
declare(strict_types=1);
namespace Corevia\Ai;
final class VectorRules
{
    public static function vector(array $vector):void{if(!array_is_list($vector)||count($vector)<1||count($vector)>4096){throw new \InvalidArgumentException('Vectors require 1..4096 dimensions.');}$norm=0;foreach($vector as $value){if(!(is_float($value)||is_int($value))||!is_finite((float)$value)||abs($value)>1e10){throw new \InvalidArgumentException('Use bounded finite vector values.');}$norm+=$value*$value;}if($norm<=0){throw new \InvalidArgumentException('Zero vectors are unsupported.');}}
    public static function identity(string $tenant,string $id):void{foreach([$tenant,$id] as $value){if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$value)){throw new \InvalidArgumentException('Explicit tenant and document IDs are required.');}}}
    public static function score(array $a,array $b):float{if(count($a)!==count($b)){throw new \InvalidArgumentException('Vector dimension mismatch.');}$dot=0;$na=0;$nb=0;foreach($a as $i=>$v){$dot+=$v*$b[$i];$na+=$v*$v;$nb+=$b[$i]*$b[$i];}return $dot/sqrt($na*$nb);}
}
