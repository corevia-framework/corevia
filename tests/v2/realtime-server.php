<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\JsonLogger;
use Corevia\Realtime\Server;
$auth=static function (string $topic, mixed $token): mixed {
    if ($token!=='test-only-token') { return false; }
    return str_starts_with($topic,'presence-') ? ['id'=>'user_1','info'=>['name'=>'Learner']] : true;
};
(new Server(new JsonLogger(),str_repeat('x',32),'127.0.0.1',(int)$argv[1],(int)$argv[2],[], $auth, heartbeatSeconds:1))->run();
