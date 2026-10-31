<?php
declare(strict_types=1);
require dirname(__DIR__).'/support/Services.php';
use CoreviaTests\Services;
$names=['COREVIA_DB_TESTS','COREVIA_TEST_DATABASE','COREVIA_TEST_SQL_DRIVERS','COREVIA_TEST_MYSQL_HOST','COREVIA_TEST_MYSQL_PORT','COREVIA_TEST_MYSQL_USER','COREVIA_TEST_MYSQL_PASSWORD','DB_DATABASE','DB_PASSWORD'];$old=[];foreach($names as $name){$old[$name]=getenv($name);}
function checkService(bool $ok):void{if(!$ok){throw new RuntimeException('Isolated test configuration assertion failed.');}}
function rejectsService(callable $work):void{try{$work();}catch(Throwable){return;}throw new RuntimeException('Expected service configuration rejection.');}
try{
    putenv('COREVIA_DB_TESTS');rejectsService(fn()=>Services::sql('mysql'));rejectsService(fn()=>Services::mongoUri());
    putenv('COREVIA_DB_TESTS=1');putenv('COREVIA_TEST_DATABASE');putenv('DB_DATABASE=production');putenv('DB_PASSWORD=must-not-inherit');putenv('COREVIA_TEST_MYSQL_HOST=mysql-stage');putenv('COREVIA_TEST_MYSQL_PORT=34067');putenv('COREVIA_TEST_MYSQL_USER=acceptance');putenv('COREVIA_TEST_MYSQL_PASSWORD=synthetic-only');$settings=Services::sql('mysql');checkService($settings['host']==='mysql-stage'&&$settings['port']===34067&&$settings['database']==='corevia_ci'&&$settings['username']==='acceptance'&&$settings['password']==='synthetic-only');
    foreach(['0','65536','3306;dbname=production'] as $port){putenv('COREVIA_TEST_MYSQL_PORT='.$port);rejectsService(fn()=>Services::sql('mysql'));}
    rejectsService(fn()=>Services::sql('sqlite'));
    putenv('COREVIA_TEST_DATABASE=corevia_test');checkService(Services::database()==='corevia_test');putenv('COREVIA_TEST_DATABASE=production');rejectsService(fn()=>Services::database());
    putenv('COREVIA_TEST_SQL_DRIVERS=pgsql');checkService(Services::drivers()===['pgsql']);putenv('COREVIA_TEST_SQL_DRIVERS=mysql,pgsql');checkService(Services::drivers()===['mysql','pgsql']);foreach(['sqlite','mysql,mysql','mysql,production'] as $selection){putenv('COREVIA_TEST_SQL_DRIVERS='.$selection);rejectsService(fn()=>Services::drivers());}
    echo "PASS explicit service-test opt-in, dedicated endpoints and fixed test database; application credentials are never inherited\n";
}finally{foreach($old as $name=>$value){putenv($value===false?$name:$name.'='.$value);}}
