<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/fixtures/v1/LegacyModule.php';
use Corevia\Core\{Corevia,Logger};
$logger=new class implements Logger { public function log(string $level,string $message,array $context=[]): void {} };
$app=Corevia::create(__DIR__,null,$logger); $app->modules([new CoreviaUpgradeFixture\LegacyModule()]); $app->boot(['legacy'=>['v1_config'=>true]]);
try {
    $run=$app->run('legacy.flow',[],'legacy-key'); $duplicate=$app->run('legacy.flow',[],'legacy-key');
    if ($run->status()!=='completed' || $run->run['state']['v1_result']!=='still works' || $duplicate->run['run_id']!==$run->run['run_id']) { throw new RuntimeException('V1 fixture incompatible'); }
    echo "PASS representative V1 module, action, flow, configuration and idempotency fixture\n";
} finally { $app->shutdown(); }
