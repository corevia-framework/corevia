<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Corevia,Flow,JobQueue,Logger,Module,ModuleContext};
use Corevia\Cli\UpgradeScanner;
$directory=sys_get_temp_dir().'/corevia-tooling-'.bin2hex(random_bytes(6)); mkdir($directory);
try {
    $quiet=new class implements Logger { public function log(string $level,string $message,array $context=[]): void {} };
    $app=Corevia::create(__DIR__,logger:$quiet);
    $app->modules([new class implements Module {
        public function name(): string { return 'empty'; } public function dependencies(): array { return []; }
        public function register(ModuleContext $c): void { $c->flows([Flow::define('empty')]); }
    }]); $app->boot();
    $queue=new JobQueue($directory); $id=$queue->dispatch('empty',[]); $future=$queue->dispatch('empty',[],time()+3600);
    if (!$queue->work($app) || $queue->work($app)) { throw new RuntimeException('Queue delivery or scheduling failed'); }
    $job=json_decode(file_get_contents($directory.'/'.$id.'.json'),true);
    if ($job['status']!=='completed' || !isset($job['run_id'])) { throw new RuntimeException('Job status missing'); }
    $app->shutdown();
    $report=(new UpgradeScanner())->scan(dirname(__DIR__,2));
    if (!$report['compatible'] || $report['mutated_files'] || $report['files_scanned']<1) { throw new RuntimeException('Upgrade scan failed'); }
    echo "PASS scheduled queue, single claim, persisted result, and read-only compatibility report\n";
} finally { foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory); }
