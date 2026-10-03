# Corevia API guide

All PHP files require PHP 8.2+ and strict types. Use `vendor/autoload.php` after Composer installation or the included root `autoload.php`.

## Modules and dependency injection

```php
$app = \Corevia\Core\Corevia::create(__DIR__, store: $store, logger: $logger);
$app->container->set(MyService::class, $service);
$app->modules([OrdersModule::class, PaymentsModule::class], disabled: ['payments']);
$app->boot(['orders' => ['currency' => 'USD']]);
$health = $app->registry->health();
```

`ModuleContext::$config` contains that module's boot configuration. `actions()` maps public names to action classes; `flows()` takes Flow objects; `on()` registers callable listeners. `Module::dependencies()` returns module IDs, not class names. Disabled/missing/failed dependencies mark dependent modules failed; healthy independent modules remain usable. Late module registrations after boot are not applied; configure all modules before boot.

## Results and state

```php
return \Corevia\Core\ActionResult::failure(
    code: 'PAYMENT_DECLINED',
    message: 'Payment declined.',
    retryable: false,
);
```

`ActionResult::success($state)` replaces flow state when it has the expected subtype. Other non-null success data is stored under the step action name in state. Null success preserves the candidate state. Flow input/output is JSON-compatible; state constructors accept an array and `jsonSerialize()` returns that array. Avoid objects/resources/non-finite numbers in state. Actions receive `ExecutionContext` containing module/flow/run/trace/request IDs and logger/event bus. `operationKey()` supplies a stable provider idempotency key.

`$app->run($flow, $input, $key, $traceId, $requestId)` returns `FlowResult`; `status()` returns the run status, and `$result->run` exposes the execution record. `$app->store->get($runId)` inspects stored records. `$app->retry($runId, $index)` retries a failed non-critical step; successful/critical steps cannot be manually replayed.

## Events and realtime

```php
$app->events->on(OrderCreated::class, function (OrderCreated $event) use ($realtime) {
    $realtime->channel('orders')->broadcast('order.updated', [
        'order_id' => $event->orderId,
    ], $event->traceId);
});
$errors = $app->events->emit(new OrderCreated($orderId, $total, $traceId));
```

Construct `$realtime` with `new Corevia\Realtime\Realtime($broadcaster)`. `EventBus` and `Broadcaster` are replaceable contracts. LocalEventBus synchronously invokes listeners, logs unexpected failures, continues other listeners and returns a list of Error values. The caller chooses whether listener errors become a flow warning. The checkout's PublishOrder action does so explicitly.

`SocketBroadcaster` connects to authenticated loopback IPC with a timeout. It acknowledges realtime process acceptance, not subscriber delivery. `Server` can also be used as an in-process broadcaster. Server constructor configures host/ports, allowed origins, private-channel authorization closure, max payload and heartbeat. Private topics start with `private-`; public channels allow alphanumeric characters and `_ . : -`, up to 128 characters. Each connection has up to 64 channels. Default limits are 512 simultaneous clients, 30 connections per IP/minute and 120 frames per client/minute.

## HTTP

```php
$router = new \Corevia\Http\Router($app, origins: ['https://app.example.com']);
$router->flow('POST', '/checkout', 'checkout');
$router->add('GET', '/orders/{id}', fn ($request) => new \Corevia\Http\Response([
    'id' => $request->params['id'],
]))->middleware($authMiddleware);
$router->dispatch(\Corevia\Http\Request::fromGlobals())->send();
```

`Middleware::process(Request $request, callable $next): Response` receives a downstream handler. The first registered middleware runs first. GET/POST/PUT/PATCH/DELETE are supported. Route placeholders capture one path segment. JSON parsing errors return 400, oversized requests 413, missing routes 404, unsupported methods 405, denied origins/actions 403, critical flow failures 422 and unexpected failures 500. A duplicate active flow returns 202. Errors use CVP response envelopes; failed flow responses also include the run record in `result` for inspection. Auth middleware should protect real business routes; the sample checkout is intentionally an open localhost demonstration.

Action gateway is absent by default. Enable only specific actions:

```php
$router->actionGateway(['orders.get'], function ($request, $action) use ($sessionAuth) {
    return $sessionAuth->canCall($request->header('authorization'), $action);
});
```

The gateway accepts only CVP `action.call`. The demo enables only `orders.get` when `COREVIA_API_TOKEN` is set. It is a development shared-token policy; implement tenant/ownership authorization before using it for private production data. Request/trace IDs are propagated from safe headers or generated. IDs from the gateway envelope are also validated and forwarded into the Action execution context.

## Validation

```php
$errors = (new \Corevia\Core\Validator())->validate($input, [
    'email' => ['required', 'email'],
    'age' => ['required', 'integer', 'min' => 18, 'max' => 130],
    'name' => ['string', 'min' => 1, 'max' => 100],
    'consent' => ['boolean'],
    'items' => ['array'],
    'reference' => [fn ($value) => str_starts_with($value, 'ref_')],
]);
```

Omitted optional fields are skipped. `required` rejects missing/null values. Integer is strict; number accepts integers/finite floats. String lengths count bytes, array bounds count elements and numeric bounds compare values. Errors map field names to failed rule names. Custom validators return boolean; they should validate types before using values.

## Logging and storage

Use `JsonLogger($path)` for JSON lines or `LoggerAdapter($psr3Logger)` to delegate to a PSR-3 compatible logger. Runtime logs include module/flow/action/run/trace IDs and duration. Credential-named context fields are redacted recursively; unexpected exception messages are excluded. Application-written log messages must also avoid secrets.

`RunStore` separates core from persistence. `MemoryRunStore` is for in-process examples/tests; `PdoRunStore(new PDO('sqlite:/path/corevia.sqlite'))` persists normal HTTP lifecycle runs. SQLite WAL and a five-second busy timeout support multiple local processes. Atomic unique flow/key reservations prevent concurrent duplicate executions. `save()` atomically persists the run and step snapshots. A separate exclusive lock protects manual retry. Implement the contract for other databases, with equivalent uniqueness/locking guarantees.

## Python and React

`Worker.action(name)` decorates a handler accepting JSON payload. Worker validates CVP, restricts targets to registered handlers and returns sanitized standard errors. Raise `CoreviaError` for a stable expected failure. The worker `authorize` callback receives `(authorization_header, action_name)`. Exceptions in authorization deny access. Network reads and PHP/SDK client calls have configurable deadlines. Handler execution itself is synchronous per worker thread and is not forcibly interrupted by network deadlines.

`Client(base_url, token=callback_or_string, timeout=5).call(...)` returns a full response envelope including IDs or raises CoreviaError. `Worker.stop()` must be called from outside the serve thread; tests demonstrate a clean threaded stop.

React helper:

```jsx
import { CoreviaContext, useCoreviaAction } from '@corevia/js/react';
function Order({ runId }) {
  const payload = React.useMemo(() => ({ run_id: runId }), [runId]);
  const { data, loading, error } = useCoreviaAction('orders.get', payload);
  return <pre>{loading ? 'Loading…' : error ? error.code : JSON.stringify(data)}</pre>;
}
// Wrap with <CoreviaContext.Provider value={client}> ... </CoreviaContext.Provider>.
```

The optional hook cancels obsolete requests on unmount/input changes. Keep its payload stable to avoid repeated calls. Actual browser/mobile/React rendering is outside the automated Node tests; the transport SDK and its reconnect/error behavior are tested independently.
