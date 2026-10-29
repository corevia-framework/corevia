<?php
declare(strict_types=1);
namespace CoreviaV3Composition;
require dirname(__DIR__, 2) . '/autoload.php';
use Corevia\Core\{ApplicationArtifact, CanonicalJson, ConfigRepository, Corevia, Logger, Module, ModuleContext};
final class Quiet implements Logger { public function log(string $level, string $message, array $context = []): void {} }
final class Sample implements Module {
    public function __construct(private string $id, private array $dependencies = []) {}
    public function name(): string { return $this->id; }
    public function dependencies(): array { return $this->dependencies; }
    public function register(ModuleContext $context): void {}
}
function check(bool $condition): void { if (!$condition) { throw new \RuntimeException('Assertion failed.'); } }
function rejects(callable $work): void { try { $work(); } catch (\Throwable) { return; } throw new \RuntimeException('Expected rejection.'); }
function app(string $root, array $modules, array $values = []): Corevia {
    $app = Corevia::create($root, logger: new Quiet(), config: new ConfigRepository($values));
    $app->compose($modules); $app->boot(); return $app;
}
$root = sys_get_temp_dir() . '/corevia-v3-build-' . bin2hex(random_bytes(8)); mkdir($root); mkdir($root . '/app');
file_put_contents($root . '/app/fixture.php', '<?php // original source'); file_put_contents($root . '/composer.lock', '{}');
$key = str_repeat('private-test-only-', 3);
try {
    $a = app($root, [new Sample('web', ['data', 'audit']), new Sample('data'), new Sample('audit')], ['secret' => 'do-not-print', 'nested' => ['z' => 1, 'a' => 2]]);
    $b = app($root, [new Sample('audit'), new Sample('data'), new Sample('web', ['audit', 'data'])], ['nested' => ['a' => 2, 'z' => 1], 'secret' => 'do-not-print']);
    check($a->registry->plan()->order === ['audit', 'data', 'web']); check(CanonicalJson::encode($a->graph()) === CanonicalJson::encode($b->graph()));
    $artifact = ApplicationArtifact::build($a, 'production', ['app'], $key); $artifact->write($root . '/artifact.json');
    $copy = ApplicationArtifact::build($b, 'production', ['app'], $key); $copy->write($root . '/copy.json');
    check(file_get_contents($root . '/artifact.json') === file_get_contents($root . '/copy.json'));
    check(!str_contains(json_encode($artifact->summary()), 'do-not-print'));
    $loaded = ApplicationArtifact::load($root . '/artifact.json', $key, 'production'); $loaded->verify($b, ['app'], $key);
    check($loaded->configuration()->get('secret') === 'do-not-print');
    rejects(fn () => ApplicationArtifact::load($root . '/artifact.json', str_repeat('wrong', 10), 'production'));
    rejects(fn () => ApplicationArtifact::load($root . '/artifact.json', $key, 'development'));
    file_put_contents($root . '/app/fixture.php', '<?php // source changed'); rejects(fn () => $loaded->verify($b, ['app'], $key));
    file_put_contents($root . '/app/fixture.php', '<?php // original source'); file_put_contents($root . '/composer.lock', '{"changed":true}'); rejects(fn () => $loaded->verify($b, ['app'], $key));
    $wire = json_decode(file_get_contents($root . '/artifact.json'), true); $wire['payload']['configuration']['application']['secret'] = 'tampered'; file_put_contents($root . '/artifact.json', json_encode($wire));
    rejects(fn () => ApplicationArtifact::load($root . '/artifact.json', $key, 'production'));
    rejects(fn () => ApplicationArtifact::build($b, 'production', ['../escape'], $key));
    $bad = Corevia::create($root, logger: new Quiet()); $bad->compose([new Sample('a', ['missing'])]); rejects(fn () => $bad->boot()); check($bad->kernel->state()->value === 'failed'); $bad->shutdown();
    $cycle = Corevia::create($root, logger: new Quiet()); $cycle->compose([new Sample('a', ['b']), new Sample('b', ['a'])]); rejects(fn () => $cycle->boot()); $cycle->shutdown();
    check(CanonicalJson::hash(['a' => [1, 2]]) !== CanonicalJson::hash(['a' => [2, 1]]));
    rejects(fn () => CanonicalJson::encode(['bad' => INF]));
    $wire=CanonicalJson::encode(['ratio'=>0.1]);$precision=ini_get('serialize_precision');
    try{ini_set('serialize_precision','17');check(CanonicalJson::encode(['ratio'=>0.1])===$wire);check(ini_get('serialize_precision')==='17');}finally{ini_set('serialize_precision',$precision);}
    check(CanonicalJson::encode(new \stdClass())==='{}');
    $a->shutdown(); $b->shutdown();
    echo "PASS V3 canonical composition, graph stability, private artifacts, drift detection and invalid plans\n";
} finally { foreach (glob($root . '/app/*') as $file) { unlink($file); } rmdir($root . '/app'); foreach (glob($root . '/*') as $file) { unlink($file); } rmdir($root); }
