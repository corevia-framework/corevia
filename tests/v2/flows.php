<?php
declare(strict_types=1);
namespace CoreviaFlowTests;
require dirname(__DIR__, 2) . '/autoload.php';
use Corevia\Core\{Action, ActionResult, Corevia, ExecutionContext, Flow, JsonRunStore, Logger, Module, ModuleContext, ParallelExecutor, State};
final class Quiet implements Logger { public function log(string $level, string $message, array $context = []): void {} }
final class Work implements Action {
    public static int $calls = 0;
    public function handle(State $s, ExecutionContext $c): State { self::$calls++; $s->data['work'] = ($s->data['work'] ?? 0) + 1; return $s; }
}
final class Undo implements Action { public function handle(State $s, ExecutionContext $c): State { $s->data['undone'] = true; return $s; } }
final class Fail implements Action { public function handle(State $s, ExecutionContext $c): ActionResult { return ActionResult::failure('BUSINESS', 'Rejected.'); } }
final class Flaky implements Action {
    public static int $calls = 0;
    public function handle(State $s, ExecutionContext $c): State { if (++self::$calls < 3) { throw new \RuntimeException('private'); } return $s; }
}
final class Slow implements Action { public function handle(State $s, ExecutionContext $c): State { usleep(4000); $s->data['late'] = true; return $s; } }
final class Fixture implements Module {
    public function __construct(private Flow $flow) {}
    public function name(): string { return 'fixture'; }
    public function dependencies(): array { return []; }
    public function register(ModuleContext $c): void { $c->actions(['work'=>Work::class,'undo'=>Undo::class,'fail'=>Fail::class,'flaky'=>Flaky::class,'slow'=>Slow::class]); $c->flows([$this->flow]); }
}
function check(bool $v): void { if (!$v) { throw new \RuntimeException('Assertion failed'); } }
function app(Flow $f, ?JsonRunStore $store = null, ?ParallelExecutor $parallel = null): Corevia { $a=Corevia::create(__DIR__, store:$store, logger:new Quiet()); if ($parallel) { $a->container->set(ParallelExecutor::class,$parallel); } $a->modules([new Fixture($f)]); $a->boot(); return $a; }
function reject(callable $f): void { try { $f(); } catch (\DomainException) { return; } throw new \RuntimeException('Expected rejection'); }
$tests=[];
$tests['persistent pause resume and duplicate delivery'] = function (): void {
    $dir=sys_get_temp_dir().'/corevia-flow-'.bin2hex(random_bytes(6)); mkdir($dir); $path=$dir.'/runs.json';
    try {
        $f=Flow::define('durable')->step('work')->waitFor('paid')->step('work')->approval('owner')->step('work');
        Work::$calls=0; $a=app($f,new JsonRunStore($path)); $r=$a->run('durable',[],'same'); check($r->status()==='paused'); $id=$r->run['run_id']; $a->shutdown();
        $b=app($f,new JsonRunStore($path)); check($b->run('durable',[],'same')->run['run_id']===$id); check(Work::$calls===1);
        reject(fn()=>$b->resume($id,'wrong')); $r=$b->resume($id,'paid'); check($r->status()==='paused'); check(Work::$calls===2);
        $r=$b->resume($id); check($r->status()==='completed'); check(Work::$calls===3); reject(fn()=>$b->resume($id)); $b->shutdown();
        $one=new JsonRunStore($path); $two=new JsonRunStore($path); check($one->lock($id)); check(!$two->lock($id)); $one->unlock($id); check($two->lock($id)); $two->unlock($id);
    } finally { foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
};
$tests['compensation succeeds and compensation failure remains visible'] = function (): void {
    foreach (['undo','fail'] as $undo) { $a=app(Flow::define('undo')->step('work')->compensate($undo)->step('fail')); $r=$a->run('undo',[]); check($r->status()==='failed'); check($r->run['compensations'][0]['status']===($undo==='undo'?'completed':'failed')); check($r->run['error']['code']==='BUSINESS'); $a->shutdown(); }
};
$tests['noncritical failure does not compensate valid work'] = function (): void {
    $a=app(Flow::define('warning')->step('work')->compensate('undo')->step('fail')->nonCritical()); $r=$a->run('warning',[]); check($r->status()==='completed_with_warnings'); check($r->run['compensations']===[]); $a->shutdown();
};
$tests['retryable exceptions obey attempt limits'] = function (): void {
    Flaky::$calls=0; $a=app(Flow::define('retry')->step('flaky')->retryPolicy(3,0,true,[\RuntimeException::class])); $r=$a->run('retry',[]); check($r->status()==='completed'); check($r->run['steps'][0]['attempts']===3); $a->shutdown();
};
$tests['deadline does not commit late output or repeat side effects'] = function (): void {
    $a=app(Flow::define('timeout')->step('slow')->timeout(1)->retry(2)); $r=$a->run('timeout',[]); check($r->run['error']['code']==='ACTION_TIMEOUT'); check(!isset($r->run['state']['late'])); check($r->run['steps'][0]['attempts']===1); $a->shutdown();
};
$tests['output contract rejects state and parallel needs adapter'] = function (): void {
    $a=app(Flow::define('output')->step('work')->outputs(['missing'])); $r=$a->run('output',[]); check($r->run['error']['code']==='OUTPUT_CONTRACT'); check($r->run['state']===[]); $a->shutdown();
    $a=app(Flow::define('parallel')->parallel(['work','work'])); check($a->run('parallel',[])->run['error']['code']==='PARALLEL_UNAVAILABLE'); $a->shutdown();
};
$tests['parallel executor results merge and conflicting outputs are rejected'] = function (): void {
    // A deterministic adapter exercises the merge contract; real concurrency belongs to infrastructure.
    $adapter=new class implements ParallelExecutor { public function execute(array $tasks): array { return array_map(fn(callable $task):array=>$task(),$tasks); } };
    $a=app(Flow::define('parallel-safe')->parallel(['work','undo']),parallel:$adapter); $r=$a->run('parallel-safe',[]); check($r->status()==='completed'); check($r->run['state']===['work'=>1,'undone'=>true]); $a->shutdown();
    $a=app(Flow::define('parallel-conflict')->parallel(['work','work']),parallel:$adapter); $r=$a->run('parallel-conflict',[]); check($r->run['error']['code']==='PARALLEL_OUTPUT_CONFLICT'); check($r->run['state']===[]); $a->shutdown();
};
$tests['changed flow definitions cannot resume persisted checkpoints'] = function (): void {
    $dir=sys_get_temp_dir().'/corevia-version-'.bin2hex(random_bytes(6));mkdir($dir);
    try { $a=app(Flow::define('versioned')->pause('review')->step('work'),new JsonRunStore($dir.'/runs.json')); $id=$a->run('versioned',[])->run['run_id']; $a->shutdown();
        $b=app(Flow::define('versioned')->pause('review')->step('undo'),new JsonRunStore($dir.'/runs.json')); reject(fn()=>$b->resume($id)); $b->shutdown();
    } finally { foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir); }
};
$failed=0; foreach ($tests as $name=>$test) { try { $test(); echo "PASS $name\n"; } catch (\Throwable $e) { $failed++; fwrite(STDERR,"FAIL $name: $e\n"); } } echo count($tests)." flow tests, $failed failed\n"; exit($failed?1:0);
