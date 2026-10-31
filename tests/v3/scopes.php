<?php
declare(strict_types=1);
namespace CoreviaV3Scopes;
require dirname(__DIR__, 2) . '/autoload.php';
use Corevia\Core\{Container, Corevia, Disposable, ExecutionScope, ScopeManager};
function check(bool $condition): void { if (!$condition) { throw new \RuntimeException('Assertion failed.'); } }
function rejects(callable $work): void { try { $work(); } catch (\Throwable) { return; } throw new \RuntimeException('Expected rejection.'); }
final class Resource implements Disposable { public bool $closed = false; public function dispose(): void { $this->closed = true; } }
$manager = new ScopeManager(); $container = new Container($manager); $container->scoped(Resource::class, Resource::class);
rejects(fn () => $container->get(Resource::class)); $first = null;
$manager->run('http', function (ExecutionScope $scope) use ($container, &$first): void {
    $first = $container->get(Resource::class); check($first === $container->get(Resource::class)); check(!$first->closed);
}); check($first->closed); check($manager->current() === null);
$manager->run('job', function () use ($container, $first): void { check($container->get(Resource::class) !== $first); });
$order = [];
try { $manager->run('job', function (ExecutionScope $scope) use (&$order): void {
    $scope->onClose(function () use (&$order): void { $order[] = 'first'; });
    $scope->onClose(function () use (&$order): void { $order[] = 'second'; throw new \LogicException('private cleanup detail'); });
    throw new \DomainException('original failure');
}); } catch (\DomainException $error) { check($error->getMessage() === 'original failure'); }
check($order === ['second', 'first']); check($manager->activeCount() === 0);
rejects(fn () => $manager->run('cli', fn (ExecutionScope $scope) => $scope->onClose(fn () => throw new \RuntimeException('private'))));
$container->singleton('bad', fn (Container $c): object => $c->get(Resource::class));
rejects(fn () => $manager->run('job', fn () => $container->get('bad')));
$parent = null; $manager->run('http', function (ExecutionScope $scope) use ($manager, &$parent): void {
    $parent = $scope; $manager->run('action', function (ExecutionScope $child) use ($scope): void { check($child->parentId === $scope->id); }); check($manager->current() === $scope);
}); check(!$parent->active());
$resources = [];
$work = function () use ($manager, $container, &$resources): void { $manager->run('job', function () use ($container, &$resources): void { $value = $container->get(Resource::class); $resources[] = $value; \Fiber::suspend(); check($value === $container->get(Resource::class)); }); };
$one = new \Fiber($work); $two = new \Fiber($work); $one->start(); $two->start(); check($manager->current() === null); check($manager->activeCount() === 2); check($resources[0] !== $resources[1]); $two->resume(); $one->resume();
check($resources[0]->closed && $resources[1]->closed); check($manager->activeCount() === 0);
$app = Corevia::create(__DIR__); $app->boot(); $app->scope('cli', function () use ($app): void { rejects(fn () => $app->shutdown()); }); $app->shutdown();
echo "PASS scoped lifetimes, reverse cleanup, failure preservation, singleton guards, nested scopes and Fiber isolation\n";
