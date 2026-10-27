<?php

declare(strict_types=1);

namespace CoreviaKernelTests;

require dirname(__DIR__, 2) . '/autoload.php';

use Corevia\Core\{Capabilities, ConfigRepository, Container, Corevia, Logger, ManifestProvider, Module, ModuleContext, ModuleLifecycle, ModuleManifest};

final class QuietLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void {}
}

final class Journal { public array $entries = []; }
final class SampleModule implements Module, ManifestProvider, ModuleLifecycle
{
    public ?ModuleContext $context = null;
    public function __construct(private string $id, private Journal $journal, private array $deps = [], private ?string $fail = null, private array $requires = []) {}
    public function name(): string { return $this->id; }
    public function dependencies(): array { return $this->deps; }
    public function manifest(): ModuleManifest { return new ModuleManifest($this->id, dependencies: $this->deps, requires: $this->requires, defaults: ['nested' => ['a' => 1, 'b' => 2]]); }
    public function register(ModuleContext $context): void { $this->context = $context; $this->visit('register'); }
    public function boot(ModuleContext $context): void { $this->visit('boot'); }
    public function ready(ModuleContext $context): void { $this->visit('ready'); }
    public function shutdown(ModuleContext $context): void { $this->visit('shutdown'); }
    private function visit(string $phase): void
    {
        $this->journal->entries[] = $this->id . '.' . $phase;
        if ($phase === $this->fail) { throw new \RuntimeException('private failure details'); }
    }
}

function check(bool $value): void { if (!$value) { throw new \RuntimeException('Assertion failed.'); } }
function rejects(callable $callback, string $class = \Throwable::class): void
{
    try { $callback(); } catch (\Throwable $error) { check($error instanceof $class); return; }
    throw new \RuntimeException('Expected exception.');
}
function app(): Corevia { return Corevia::create(__DIR__, logger: new QuietLogger()); }

$tests = [];
$tests['dependency lifecycle order and idempotent cleanup'] = function (): void {
    $j = new Journal(); $a = app();
    $a->modules([new SampleModule('child', $j, ['parent']), new SampleModule('parent', $j)]);
    $a->onReady(function (Corevia $a) use ($j): void { check($a->kernel->state()->value === 'ready'); $j->entries[] = 'app.ready'; });
    $a->onShutdown(function () use ($j): void { $j->entries[] = 'app.shutdown'; });
    $a->boot(); $a->boot(); check($a->shutdown() === []); check($a->shutdown() === []);
    check($j->entries === ['parent.register', 'child.register', 'parent.boot', 'child.boot', 'parent.ready', 'child.ready', 'app.ready', 'app.shutdown', 'child.shutdown', 'parent.shutdown']);
    check(array_column($a->kernel->timeline(), 'state') === ['created', 'registering', 'booting', 'ready', 'shutting_down', 'shutdown']);
    rejects(fn () => $a->boot(), \LogicException::class);
};
$tests['ready failure cleans transitive dependents in reverse order'] = function (): void {
    $j = new Journal(); $a = app();
    $a->modules([new SampleModule('leaf', $j, ['child']), new SampleModule('child', $j, ['parent']), new SampleModule('parent', $j, fail: 'ready'), new SampleModule('independent', $j)]);
    $a->boot();
    check(array_slice($j->entries, 8, 4) === ['parent.ready', 'leaf.shutdown', 'child.shutdown', 'parent.shutdown']);
    foreach (['parent', 'child', 'leaf'] as $id) { check($a->registry->health()[$id]['status'] === 'failed'); }
    check(array_keys($a->registry->contexts()) === ['independent']);
    check(!str_contains(json_encode($a->registry->health()), 'private failure'));
    $a->shutdown(); check(end($j->entries) === 'independent.shutdown');
};
$tests['boot failure cleans partial resource acquisition'] = function (): void {
    $j = new Journal(); $a = app();
    $a->modules([new SampleModule('child', $j, ['parent']), new SampleModule('parent', $j, fail: 'boot')]); $a->boot(); $a->shutdown();
    check($j->entries === ['parent.register', 'child.register', 'parent.boot', 'parent.shutdown']);
};
$tests['shutdown errors do not prevent remaining cleanup'] = function (): void {
    $j = new Journal(); $a = app(); $a->modules([new SampleModule('parent', $j), new SampleModule('child', $j, ['parent'], 'shutdown')]);
    $a->onShutdown(fn () => throw new \RuntimeException('private')); $a->boot();
    check(count($a->shutdown()) === 2); check(array_slice($j->entries, -2) === ['child.shutdown', 'parent.shutdown']); check($a->kernel->state()->value === 'shutdown');
};
$tests['application ready failure permits explicit cleanup'] = function (): void {
    $j = new Journal(); $a = app(); $a->modules([new SampleModule('one', $j)]); $a->onReady(fn () => throw new \RuntimeException('failed'));
    rejects(fn () => $a->boot()); check($a->kernel->state()->value === 'failed'); $a->shutdown(); check(end($j->entries) === 'one.shutdown');
};
$tests['readiness and late registration guards'] = function (): void {
    $a = app(); rejects(fn () => $a->run('unknown', []), \LogicException::class); $a->boot();
    rejects(fn () => $a->modules([]), \LogicException::class); rejects(fn () => $a->onReady(fn () => null), \LogicException::class);
    $a->shutdown(); rejects(fn () => $a->call('unknown', [], 'trace', 'request'), \LogicException::class);
};
$tests['manifest defaults, V1 overrides, frozen context'] = function (): void {
    $j = new Journal(); $m = new SampleModule('one', $j); $a = Corevia::create(__DIR__, logger: new QuietLogger(), config: new ConfigRepository(['modules' => ['one' => ['nested' => ['a' => 3]]]]));
    $a->modules([$m]); $a->boot(['one' => ['nested' => ['b' => 4]]]);
    check($m->context->config === ['nested' => ['a' => 3, 'b' => 4]]); check($m->context->container === $a->container);
    rejects(fn () => $m->context->actions([]), \LogicException::class); rejects(fn () => $a->config->set('late', true), \LogicException::class); $a->shutdown();
};
$tests['V1 modules receive synthesized manifests'] = function (): void {
    $m = new class implements Module {
        public function name(): string { return 'legacy'; }
        public function dependencies(): array { return []; }
        public function register(ModuleContext $context): void {}
    };
    $a = app(); $a->modules([$m]); $a->boot(); check($a->registry->manifests()['legacy']->id === 'legacy'); check($a->registry->health()['legacy']['status'] === 'healthy'); $a->shutdown();
};
$tests['capability requirements isolate unavailable modules'] = function (): void {
    $j = new Journal(); $a = app(); $a->capabilities->define('optional.cache', false);
    $a->modules([new SampleModule('bad', $j, requires: ['optional.cache']), new SampleModule('good', $j, requires: ['core.flows'])]); $a->boot();
    check($a->registry->health()['bad']['status'] === 'failed'); check($j->entries === ['good.register', 'good.boot', 'good.ready']); $a->shutdown();
    rejects(fn () => (new Capabilities())->define('../invalid'), \InvalidArgumentException::class);
};
$tests['manifest rejects invalid versions and mismatched contracts'] = function (): void {
    rejects(fn () => new ModuleManifest('one', 'invalid'), \InvalidArgumentException::class);
    $m = new class implements Module, ManifestProvider {
        public function name(): string { return 'one'; }
        public function dependencies(): array { return []; }
        public function manifest(): ModuleManifest { return new ModuleManifest('other'); }
        public function register(ModuleContext $context): void { throw new \RuntimeException('Must not register.'); }
    };
    $a = app(); $a->modules([$m]); $a->boot(); check($a->registry->health()['one']['status'] === 'failed'); $a->shutdown();
};
$tests['container factories are lazy, transient or shared'] = function (): void {
    $c = new Container(); $count = 0;
    $c->singleton('shared', function () use (&$count): object { $count++; return new \stdClass(); }); check($count === 0); check($c->get('shared') === $c->get('shared')); check($count === 1);
    $c->bind('fresh', fn (): object => new \stdClass()); check($c->get('fresh') !== $c->get('fresh'));
    $c->bind('alias', 'shared'); check($c->get('alias') === $c->get('shared'));
    $replacement = new \stdClass(); $c->set('shared', $replacement); check($c->get('shared') === $replacement);
};
$tests['container catches cycles and recovers after failed factories'] = function (): void {
    $c = new Container(); $c->bind('a', fn (Container $c): object => $c->get('b')); $c->bind('b', fn (Container $c): object => $c->get('a'));
    rejects(fn () => $c->get('a'), \RuntimeException::class); $c->bind('a', fn (): object => new \stdClass()); check($c->get('b') instanceof \stdClass);
    $c->bind('bad', fn () => null); rejects(fn () => $c->get('bad'), \RuntimeException::class);
};
$tests['configuration merges maps and replaces lists'] = function (): void {
    check(ConfigRepository::merge(['map' => ['a' => 1, 'b' => 2], 'list' => [1, 2]], ['map' => ['a' => 3], 'list' => [9]]) === ['map' => ['a' => 3, 'b' => 2], 'list' => [9]]);
    check(ConfigRepository::merge(['map' => ['a' => 1]], ['map' => []]) === ['map' => []]);
    $c = new ConfigRepository(); $c->set('app.name', 'Corevia'); check($c->get('app.name') === 'Corevia'); check($c->get('missing', 'fallback') === 'fallback');
    rejects(fn () => $c->set('app.name.child', 1)); rejects(fn () => new ConfigRepository(['bad' => new \stdClass()])); rejects(fn () => new ConfigRepository(['bad' => INF]));
};
$tests['configuration overlay and cache round trip'] = function (): void {
    $dir = sys_get_temp_dir() . '/corevia-config-' . bin2hex(random_bytes(8)); mkdir($dir); mkdir($dir . '/environments'); mkdir($dir . '/environments/test');
    try {
        file_put_contents($dir . '/app.php', '<?php return ["name" => "base", "list" => [1, 2]];');
        file_put_contents($dir . '/environments/test/app.php', '<?php return ["name" => "test", "list" => [3]];');
        $c = ConfigRepository::load($dir, 'test'); check($c->get('app.name') === 'test'); check($c->get('app.list') === [3]);
        $c->cache($dir . '/cache.json', 'test'); check(ConfigRepository::load($dir . '/absent', 'test', $dir . '/cache.json')->all() === $c->all());
        rejects(fn () => ConfigRepository::load($dir, 'production', $dir . '/cache.json')); rejects(fn () => ConfigRepository::load($dir, '../test'));
        file_put_contents($dir . '/cache.json', '{}'); rejects(fn () => ConfigRepository::load($dir, 'test', $dir . '/cache.json'));
    } finally {
        foreach ([$dir . '/cache.json', $dir . '/app.php', $dir . '/environments/test/app.php'] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($dir . '/environments/test'); rmdir($dir . '/environments'); rmdir($dir);
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS $name\n"; } catch (\Throwable $error) { $failed++; fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n{$error->getTraceAsString()}\n"); }
}
echo count($tests) . " kernel tests, $failed failed\n";
exit($failed === 0 ? 0 : 1);
