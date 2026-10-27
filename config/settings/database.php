<?php
declare(strict_types=1);
$storage=getenv('COREVIA_STORAGE') ?: dirname(__DIR__,2).'/storage';
return [
    'default'=>getenv('DB_CONNECTION') ?: 'local',
    'connections'=>[
        'local'=>['driver'=>'json','path'=>$storage.'/documents'],
        'sqlite'=>['driver'=>'sqlite','database'=>getenv('SQLITE_DATABASE') ?: $storage.'/application.sqlite'],
        'mysql'=>['driver'=>'mysql','host'=>getenv('DB_HOST') ?: '127.0.0.1','port'=>getenv('DB_PORT') ?: 3306,'database'=>getenv('DB_DATABASE') ?: 'corevia','username'=>getenv('DB_USERNAME') ?: '','password'=>getenv('DB_PASSWORD') ?: '', 'charset'=>'utf8mb4'],
        'pgsql'=>['driver'=>'pgsql','host'=>getenv('DB_HOST') ?: '127.0.0.1','port'=>getenv('DB_PORT') ?: 5432,'database'=>getenv('DB_DATABASE') ?: 'corevia','username'=>getenv('DB_USERNAME') ?: '','password'=>getenv('DB_PASSWORD') ?: '', 'sslmode'=>getenv('DB_SSLMODE') ?: 'prefer'],
        'mongodb'=>['driver'=>'mongodb','uri'=>getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017','database'=>getenv('MONGODB_DATABASE') ?: 'corevia'],
    ],
];
