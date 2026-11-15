<?php
declare(strict_types=1);
namespace Corevia\Core;
final class ExceptionIdentity
{
    /** Anonymous PHP class identities can contain NULs and absolute source paths. */
    public static function name(\Throwable $error):string
    {
        $name=$error::class;
        return strlen($name)<=191&&preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\\\\)*[a-zA-Z_][a-zA-Z0-9_]*$/D',$name)?$name:'Throwable';
    }
}
