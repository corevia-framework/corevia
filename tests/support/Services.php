<?php
declare(strict_types=1);
namespace CoreviaTests;

/** Disposable acceptance services only. Never inherits application DB credentials. */
final class Services
{
    public const DATABASE='corevia_ci';
    private static function enabled():void
    {
        if(getenv('COREVIA_DB_TESTS')!=='1'){throw new \LogicException('Explicit COREVIA_DB_TESTS=1 is required for service tests.');}
    }
    public static function drivers():array
    {
        self::enabled();$drivers=explode(',',getenv('COREVIA_TEST_SQL_DRIVERS')?:'mysql,pgsql');
        if($drivers===[]||count($drivers)!==count(array_unique($drivers))||array_diff($drivers,['mysql','pgsql'])!==[]){throw new \InvalidArgumentException('Select mysql, pgsql or both explicitly.');}
        return $drivers;
    }
    public static function database():string
    {
        self::enabled();$name=getenv('COREVIA_TEST_DATABASE')?:self::DATABASE;
        if(!in_array($name,['corevia_ci','corevia_test'],true)){throw new \InvalidArgumentException('Service tests require an explicitly disposable corevia_ci or corevia_test database.');}
        return $name;
    }
    public static function sql(string $driver):array
    {
        self::enabled();$prefix=match($driver){'mysql'=>'COREVIA_TEST_MYSQL_','pgsql'=>'COREVIA_TEST_PGSQL_',default=>throw new \InvalidArgumentException('Only isolated MySQL/PostgreSQL acceptance is supported.')};
        $configuredPort=getenv($prefix.'PORT');$port=filter_var($configuredPort===false||$configuredPort===''?($driver==='mysql'?3306:5432):$configuredPort,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $host=getenv($prefix.'HOST')?:'127.0.0.1';if($port===false||str_contains($host,';')||preg_match('/[\x00-\x20]/',$host)){throw new \InvalidArgumentException('Invalid isolated service endpoint.');}
        return ['driver'=>$driver,'host'=>$host,'port'=>$port,'database'=>self::database(),'username'=>getenv($prefix.'USER')?:'corevia','password'=>getenv($prefix.'PASSWORD')?:'ci-only-password','sslmode'=>'disable'];
    }
    public static function mongoUri():string
    {
        self::enabled();$uri=getenv('COREVIA_TEST_MONGODB_URI')?:'mongodb://127.0.0.1:27017';
        if(!str_starts_with($uri,'mongodb://')&&!str_starts_with($uri,'mongodb+srv://')){throw new \InvalidArgumentException('Configure an isolated MongoDB test URI.');}
        return $uri;
    }
}
