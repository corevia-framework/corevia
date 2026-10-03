<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use App\Notifications\Actions\SendConfirmation;
use App\Notifications\NotificationsModule;
use App\Orders\Actions\FraudCheck;
use App\Orders\OrdersModule;
use App\Payments\PaymentsModule;
use App\Shared\DemoLedger;
use Corevia\Core\{Action, ActionResult, Container, Corevia, EventBus, ExecutionContext, Flow, JsonLogger, LocalEventBus, Logger, LoggerAdapter, MemoryRunStore, Module, ModuleContext, ModuleRegistry, RunStore, State, Validator};
use Corevia\Http\{Middleware, Request, Response, Router};
use Corevia\Persistence\PdoRunStore;
use Corevia\Protocol\{Protocol, RemoteAction};
use Corevia\Realtime\{Broadcaster, Frame, Realtime};

final class TestLogger implements Logger
{
    public array $records = [];
    public function log(string $level, string $message, array $context = []): void { $this->records[] = [$level, $message, $context]; }
}

final class TestBroadcast implements Broadcaster
{
    public array $messages = [];
    public function broadcast(string $channel, string $event, array $payload, ?string $traceId = null): void { $this->messages[] = [$channel, $event, $payload, $traceId]; }
}

final class IncrementAction implements Action
{
    public function handle(State $state, ExecutionContext $context): State
    {
        $state->data['count'] = ($state->data['count'] ?? 0) + 1;
        return $state;
    }
}

final class ExplodeAction implements Action
{
    public function handle(State $state, ExecutionContext $context): State
    {
        $state->data['corrupted'] = true;
        throw new RuntimeException('unexpected secret');
    }
}

final class FlakyAction implements Action
{
    public int $calls = 0;
    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        $this->calls++;
        if ($this->calls < 3) {
            $state->data['corrupted'] = true;
            return ActionResult::failure('TEMPORARY', 'Try again.', true);
        }
        $state->data['recovered'] = true;
        return $state;
    }
}

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function throws(callable $fn, string $class = Throwable::class): void
{
    try { $fn(); } catch (Throwable $exception) { check($exception instanceof $class, $exception::class); return; }
    throw new RuntimeException('Expected exception.');
}

function module(string $name, array $dependencies = [], ?Closure $register = null): Module
{
    return new class ($name, $dependencies, $register) implements Module {
        public function __construct(private string $id, private array $deps, private ?Closure $callback) {}
        public function name(): string { return $this->id; }
        public function dependencies(): array { return $this->deps; }
        public function register(ModuleContext $context): void { if ($this->callback) { ($this->callback)($context); } }
    };
}

function demo(?RunStore $store = null): array
{
    $logger = new TestLogger();
    $app = Corevia::create(dirname(__DIR__), $store, $logger);
    $pdo = new PDO('sqlite::memory:');
    $ledger = new DemoLedger($pdo);
    $broadcast = new TestBroadcast();
    $recovery = sys_get_temp_dir() . '/corevia-recovery-' . bin2hex(random_bytes(8));
    $app->container->set(DemoLedger::class, $ledger);
    $app->container->set(Broadcaster::class, $broadcast);
    $app->container->set(RunStore::class, $app->store);
    $app->container->set(SendConfirmation::class, new SendConfirmation($ledger, $recovery));
    $app->container->set(FraudCheck::class, new FraudCheck());
    $app->modules([OrdersModule::class, PaymentsModule::class, NotificationsModule::class]);
    $app->boot();
    $input = json_decode(file_get_contents(dirname(__DIR__) . '/examples/checkout.json'), true, 64, JSON_THROW_ON_ERROR);
    return compact('app', 'ledger', 'broadcast', 'logger', 'input', 'recovery');
}

$tests = [];
$tests['container resolves bindings and rejects unresolved scalar dependencies'] = static function (): void {
    $container = new Container();
    $logger = new TestLogger();
    $container->set(Logger::class, $logger);
    check($container->get(Logger::class) === $logger);
    check($container->get(IncrementAction::class) instanceof IncrementAction);
    throws(fn () => $container->get(ExecutionContext::class));
};
$tests['module failure and unavailable dependencies leave independent modules healthy'] = static function (): void {
    $registry = new ModuleRegistry(new TestLogger());
    $registry->add(module('bad', [], static function (): void { throw new RuntimeException('broken'); }));
    $registry->add(module('dependent', ['bad']));
    $registry->add(module('independent'));
    $registry->add(module('disabled'), false);
    $registry->add(module('missing', ['absent']));
    $registry->boot();
    $health = $registry->health();
    check($health['bad']['status'] === 'failed' && $health['dependent']['status'] === 'failed');
    check($health['independent']['status'] === 'healthy' && $health['disabled']['status'] === 'disabled');
    check($health['missing']['status'] === 'failed');
    throws(fn () => $registry->add(module('independent')));
};
$tests['circular module dependencies are isolated'] = static function (): void {
    $registry = new ModuleRegistry(new TestLogger());
    $registry->add(module('a', ['b'])); $registry->add(module('b', ['a'])); $registry->add(module('c'));
    $registry->boot();
    check($registry->health()['a']['status'] === 'failed' && $registry->health()['c']['status'] === 'healthy');
};
$tests['module registrations are atomic and duplicate public names fail'] = static function (): void {
    $registry = new ModuleRegistry(new TestLogger());
    $callback = static fn (ModuleContext $context) => $context->actions(['increment' => IncrementAction::class]);
    $registry->add(module('a', [], $callback)); $registry->add(module('b', [], $callback));
    $registry->boot();
    check(count($registry->contexts()) === 1 && $registry->health()['b']['status'] === 'failed');
    throws(fn () => (new ModuleContext('x'))->actions(['bad' => stdClass::class]));
};
$tests['flow action dependencies must be declared and class-based steps execute'] = static function (): void {
    $registry = new ModuleRegistry(new TestLogger());
    $registry->add(module('owner', [], static fn (ModuleContext $ctx) => $ctx->actions(['inc' => IncrementAction::class])));
    $registry->add(module('unrelated', [], static fn (ModuleContext $ctx) => $ctx->flows([Flow::define('forbidden')->step('inc')])));
    $registry->boot();
    check($registry->health()['unrelated']['status'] === 'failed');
    $app = Corevia::create(__DIR__, logger: new TestLogger());
    $app->modules([module('owner', [], static function (ModuleContext $ctx): void {
        $ctx->actions(['inc' => IncrementAction::class]);
        $ctx->flows([Flow::define('class-flow')->step(IncrementAction::class)]);
    })]);
    $app->boot();
    check($app->run('class-flow', [])->run['state']['count'] === 1);
};
$tests['non-critical notification failure preserves the order and emits an event'] = static function (): void {
    extract(demo());
    $result = $app->run('checkout', $input, 'checkout-one', 'trace-one', 'request-one');
    check($result->status() === 'completed_with_warnings');
    check($result->run['steps'][5]['attempts'] === 6 && $result->run['steps'][6]['status'] === 'completed');
    check($ledger->count('order') === 1 && $ledger->count('charge') === 1);
    check($broadcast->messages[0][1] === 'order.updated' && $broadcast->messages[0][3] === 'trace-one');
    check($result->run['request_id'] === 'request-one');
    check($logger->records[0][2]['trace_id'] === 'trace-one');
};
$tests['critical payment failure stops subsequent order and notification actions'] = static function (): void {
    extract(demo());
    $result = $app->run('checkout', [...$input, 'decline_payment' => true]);
    check($result->status() === 'failed' && $result->run['error']['code'] === 'PAYMENT_DECLINED');
    check(count($result->run['steps']) === 4 && $ledger->count('order') === 0);
};
$tests['duplicate flow requests do not repeat committed work and reject input conflicts'] = static function (): void {
    extract(demo());
    $first = $app->run('checkout', $input, 'same-key');
    $second = $app->run('checkout', $input, 'same-key');
    check($first->run['run_id'] === $second->run['run_id'] && $ledger->count('charge') === 1);
    throws(fn () => $app->run('checkout', [...$input, 'extra' => true], 'same-key'), DomainException::class);
};
$tests['manual non-critical retry recovers without repeating payment'] = static function (): void {
    extract(demo());
    $run = $app->run('checkout', $input);
    touch($recovery);
    try {
        $result = $app->retry($run->run['run_id'], 5);
        check($result->status() === 'completed' && $result->run['state']['confirmation_sent']);
        check($ledger->count('charge') === 1 && $ledger->count('confirmation') === 1);
        throws(fn () => $app->retry($run->run['run_id'], 3), DomainException::class);
    } finally { unlink($recovery); }
};
$tests['conditions, retry policies, rollback of failed state and unexpected exceptions'] = static function (): void {
    $app = Corevia::create(__DIR__, logger: new TestLogger());
    $flaky = new FlakyAction(); $app->container->set(FlakyAction::class, $flaky);
    $flow = Flow::define('test')->step('inc')->when(static fn (State $s): bool => false)->step('flaky')->retry(2, 1, true)->step('explode')->nonCritical()->step('inc');
    $app->modules([module('test', [], static function (ModuleContext $ctx) use ($flow): void {
        $ctx->actions(['inc' => IncrementAction::class, 'flaky' => FlakyAction::class, 'explode' => ExplodeAction::class]); $ctx->flows([$flow]);
    })]);
    $app->boot(); $app->boot();
    $run = $app->run('test', []);
    check($run->run['steps'][0]['status'] === 'skipped' && $run->run['steps'][1]['attempts'] === 3);
    check($run->run['steps'][2]['error']['code'] === 'ACTION_EXCEPTION');
    check($run->run['state']['count'] === 1 && !isset($run->run['state']['corrupted']));
    throws(fn () => Flow::define('bad')->retry(-1));
    throws(fn () => Flow::define('bad', stdClass::class));
};
$tests['event bus isolates listener exceptions and continues dispatch'] = static function (): void {
    $bus = new LocalEventBus(new TestLogger()); $called = 0;
    $bus->on(stdClass::class, static function (): void { throw new RuntimeException('listener'); });
    $bus->on(stdClass::class, static function () use (&$called): void { $called++; });
    check(count($bus->emit(new stdClass())) === 1 && $called === 1);
};
$tests['HTTP checkout integration propagates ids, CORS and structured errors'] = static function (): void {
    extract(demo()); $router = new Router($app, ['https://example.test']); $router->flow('POST', '/checkout', 'checkout');
    $request = new Request('POST', '/checkout', ['x-request-id' => 'request-http', 'x-trace-id' => 'trace-http', 'origin' => 'https://example.test'], json_encode($input));
    $response = $router->dispatch($request);
    check($response->status === 200 && $response->body['result']['trace_id'] === 'trace-http');
    check($response->body['id'] === 'request-http' && $response->headers['Access-Control-Allow-Origin'] === 'https://example.test');
    check($router->dispatch(new Request('OPTIONS', '/checkout', ['origin' => 'https://example.test']))->status === 204);
    check($router->dispatch(new Request('POST', '/checkout', ['origin' => 'https://evil.test']))->status === 403);
    check($router->dispatch(new Request('POST', '/checkout', [], 'bad json'))->status === 400);
    check($router->dispatch(new Request('GET', '/checkout'))->status === 405);
    check($router->dispatch(new Request('POST', '/checkout', [], str_repeat('x', 65537)))->status === 413);
};
$tests['router parameters and middleware support all HTTP verbs'] = static function (): void {
    extract(demo()); $router = new Router($app);
    $middleware = new class implements Middleware {
        public function process(Request $request, callable $next): Response { return $next($request)->withHeaders(['X-Middleware' => 'yes']); }
    };
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $router->add($method, '/items/{id}', static fn (Request $r): Response => new Response($r->params))->middleware($middleware);
        $response = $router->dispatch(new Request($method, '/items/42'));
        check($response->status === 200 && ($response->body['id'] ?? null) === '42' && $response->headers['X-Middleware'] === 'yes');
    }
    check(count($router->routes()) === 5);
};
$tests['action gateway requires explicit allowlist and authorization'] = static function (): void {
    extract(demo()); $router = new Router($app);
    check($router->dispatch(new Request('POST', '/cvp'))->status === 404);
    $router->actionGateway(['orders.get'], static fn (Request $r): bool => $r->header('authorization') === 'Bearer good');
    $wire = (new Protocol())->encode(Protocol::request('action.call', 'orders.get', ['run_id' => 'missing']));
    check($router->dispatch(new Request('POST', '/cvp', [], $wire))->status === 403);
    $responseWire = (new Protocol())->encode(Protocol::response('wrong_type', ActionResult::success([])));
    check($router->dispatch(new Request('POST', '/cvp', [], $responseWire))->status === 403);
    $response = $router->dispatch(new Request('POST', '/cvp', ['authorization' => 'Bearer good'], $wire));
    check($response->status === 422 && $response->body['error']['code'] === 'NOT_FOUND');
};
$tests['PDO runs survive new connections and reserve idempotency keys atomically'] = static function (): void {
    $path = sys_get_temp_dir() . '/corevia-store-' . bin2hex(random_bytes(8)) . '.sqlite';
    try {
        $store = new PdoRunStore(new PDO('sqlite:' . $path));
        extract(demo($store));
        $first = $app->run('checkout', $input, 'persistent');
        $other = new PdoRunStore(new PDO('sqlite:' . $path));
        check($other->get($first->run['run_id'])['state']['order_id'] === $first->run['state']['order_id']);
        extract(demo($other));
        $second = $app->run('checkout', $input, 'persistent');
        check($second->run['run_id'] === $first->run['run_id'] && $ledger->count('order') === 0);
        check($store->lock($first->run['run_id']) && !$other->lock($first->run['run_id']));
        $store->unlock($first->run['run_id']);
        check($other->lock($first->run['run_id'])); $other->unlock($first->run['run_id']);
    } finally {
        unset($store, $other, $app);
        gc_collect_cycles();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { @unlink($file); } }
    }
};
$tests['protocol shared valid/invalid fixtures and size/security bounds'] = static function (): void {
    $protocol = new Protocol();
    foreach (json_decode(file_get_contents(dirname(__DIR__) . '/protocol/fixtures/valid.json'), true) as $fixture) {
        check($protocol->decode($protocol->encode($fixture)) === $fixture);
    }
    foreach (json_decode(file_get_contents(dirname(__DIR__) . '/protocol/fixtures/invalid.json'), true) as $fixture) {
        throws(fn () => $protocol->decode(json_encode($fixture)), InvalidArgumentException::class);
    }
    throws(fn () => $protocol->decode('bad'));
    throws(fn () => (new Protocol(5))->decode(str_repeat('a', 6)));
    throws(fn () => $protocol->encode(Protocol::request('action.call', 'x', ['object' => new stdClass()])));
    throws(fn () => $protocol->encode(Protocol::request('action.call', 'x', ['number' => INF])));
};
$tests['lightweight validator supports typed, numeric, length and custom rules'] = static function (): void {
    $validator = new Validator();
    check($validator->validate(['name' => 'hello', 'n' => 4, 'b' => true, 'a' => [], 'e' => 'a@b.com'], ['name' => ['required', 'string', 'min' => 2, 'max' => 8], 'n' => ['integer', 'number', 'min' => 0], 'b' => ['boolean'], 'a' => ['array'], 'e' => ['email']]) === []);
    check(isset($validator->validate([], ['missing' => ['required']])['missing']));
    check(isset($validator->validate(['n' => '4'], ['n' => ['integer']])['n']));
    check($validator->validate(['n' => 4], ['n' => [static fn ($v): bool => $v === 4]]) === []);
};
$tests['WebSocket frame codec handles masked, partial and oversized frames'] = static function (): void {
    $mask = 'abcd'; $payload = 'hello'; $masked = '';
    for ($i = 0; $i < strlen($payload); $i++) { $masked .= $payload[$i] ^ $mask[$i % 4]; }
    $wire = chr(0x81) . chr(0x80 | strlen($payload)) . $mask . $masked;
    $partial = substr($wire, 0, 4); check(Frame::decode($partial, 100) === null);
    check(Frame::decode($wire, 100)['payload'] === 'hello' && $wire === '');
    $unmasked = Frame::encode('hello'); throws(static function () use (&$unmasked): void { Frame::decode($unmasked, 100); });
    check(strlen(Frame::encode(str_repeat('x', 65536))) === 65546);
};
$tests['realtime channel API passes trace ids to broadcaster'] = static function (): void {
    $broadcast = new TestBroadcast(); (new Realtime($broadcast))->channel('orders')->broadcast('updated', ['id' => 1], 'trace');
    check($broadcast->messages === [['orders', 'updated', ['id' => 1], 'trace']]);
};
$tests['logs redact nested credentials and PSR-3 adapter delegates'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'cvlog');
    try {
        (new JsonLogger($path))->log('info', 'safe message', ['token' => 'secret', 'nested' => ['authorization' => 'raw']]);
        $raw = file_get_contents($path); check(!str_contains($raw, 'secret') && !str_contains($raw, 'raw'));
    } finally { unlink($path); }
    $target = new TestLogger(); (new LoggerAdapter($target))->log('info', 'delegated'); check(count($target->records) === 1);
};
$tests['remote calls reject unsafe transports and classify unavailable runtime'] = static function (): void {
    throws(fn () => new RemoteAction('file:///etc/passwd'));
    check(!(new RemoteAction('http://127.0.0.1:1/cvp', timeout: 0.05))->call('x', [])->ok);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $exception) { $failures++; echo "FAIL $name: {$exception->getMessage()}\n{$exception->getTraceAsString()}\n"; }
}
echo count($tests) . " tests, $failures failures.\n";
exit($failures ? 1 : 0);
