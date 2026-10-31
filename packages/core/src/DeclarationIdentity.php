<?php
declare(strict_types=1);
namespace Corevia\Core;

/** @internal Portable declaration metadata; anonymous PHP names embed absolute paths. */
final class DeclarationIdentity
{
    public static function normalize(mixed $value): mixed
    {
        if(is_string($value) && str_contains($value,"\0") && class_exists($value,false)){
            $class=new \ReflectionClass($value);$file=$class->getFileName();
            if($file===false || !is_file($file)){throw new \LogicException('Anonymous declarations require inspectable source files.');}
            return 'anonymous@'.hash_file('sha256',$file).':'.$class->getStartLine().'-'.$class->getEndLine();
        }
        if(!is_array($value)){return $value;}
        $result=[];foreach($value as $key=>$item){$id=is_string($key)?self::normalize($key):$key;if(array_key_exists($id,$result)){throw new \LogicException('Ambiguous anonymous declarations; use named classes.');}$result[$id]=self::normalize($item);}
        if(!array_is_list($result)){ksort($result,SORT_STRING);}return $result;
    }
}
