<?php
declare(strict_types=1);
namespace Corevia\Testing;
final class Guard
{
    public static function development():void
    {
        if(getenv('APP_ENV')==='production'){throw new \LogicException('Testing helpers cannot run in production.');}
    }
}
