# Corevia

The `main` branch and `v1.0.0` tag preserve V1. The `develop/v2` branch adds the V2 kernel while retaining the V1 flow and protocol APIs. Start with the [V2 kernel guide](docs/v2/kernel.md) and run `php -n examples/v2/kernel.php` for a database-free lifecycle example. See [phase progress](docs/v2/phases.md) and [compatibility](docs/v2/compatibility.md).

A framework-independent PHP 8.2+ runtime for modular actions, observable flows, isolated failures, events, WebSockets and CVP/1 interoperability. PHP core has no third-party runtime dependencies and does not require Node.js, Python, Redis or a broker.

## Setup

New to Corevia? Start with the separate [database-free learning project](test-project/README.md). Run `php test-project/console.php serve` and open http://127.0.0.1:8091 to create tasks and inspect each flow step. Its [database guide](test-project/DATABASE.md) explains how to switch task storage to PDO/MySQL later.

Requirements: PHP 8.2+ with JSON and PDO; PDO SQLite for the sample app and persistence adapter. Composer is supported; the included PSR-4 loader also allows immediate local use. Node.js 22+ and Python 3.10+ are needed only for SDKs and cross-language tests.

```sh
composer install
php corevia doctor
php corevia flow:run checkout --input=examples/checkout.json --key=demo-checkout
```

The checkout creates a **simulated** payment and order in SQLite. Notification delivery intentionally fails, so its result is `completed_with_warnings`. Without a realtime process, event delivery also reports a warning. Corevia does not charge money or send email in this sample.

## HTTP and realtime demo

Run these in separate terminals from the project directory:

```sh
php corevia realtime:start
php corevia serve
```

Open **http://127.0.0.1:8081**. Click **Run checkout** to view step results and receive `order.updated` over WebSocket. The default listener uses port 8080 and authenticated local IPC on port 8082. Keep the realtime process running independently of PHP-FPM.

```sh
curl -X POST http://127.0.0.1:8081/checkout \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: checkout-example-1' \
  --data-binary @examples/checkout.json
php corevia flow:show <run-id>
```

To recover the notification service in the demo, create `storage/notifications.online`:

```powershell
New-Item -ItemType File -Path storage/notifications.online
php corevia flow:retry <run-id> 5
```

On Unix, use `touch storage/notifications.online`. Indexes are zero-based: notification is step 5, broadcast is step 6. Retry executes only that failed non-critical step, with the same operation key; payment and order creation are preserved. Recover a broadcast warning after starting realtime with `php corevia flow:retry <run-id> 6`. Stop realtime gracefully with `php corevia realtime:stop` or SIGINT/SIGTERM on Unix.

## Python integration

The sample uses a local fraud threshold by default to keep PHP-only setup simple. For the complete PHP → Python → WebSocket demo, set the worker settings in both the Python and PHP terminals **before** starting the servers:

```powershell
$env:COREVIA_WORKER_TOKEN = 'choose-a-development-shared-secret'
$env:COREVIA_FRAUD_URL = 'http://127.0.0.1:8090/cvp'
python examples/python_worker.py
```

In another terminal with the same environment settings:

```powershell
php corevia realtime:start
```

In a third terminal with the same settings:

```powershell
php corevia serve
```

On Unix, export `COREVIA_WORKER_TOKEN` and `COREVIA_FRAUD_URL` instead. Use your installed Python executable if the `python` alias is unavailable. `php examples/remote.php` calls the Python `ai.analyze` action. Remote fraud calls report `fraud_runtime: python` in checkout state and preserve the flow trace ID. The worker's analysis action counts words and characters; it does not call an AI provider.

## Architecture

```text
HTTP / CLI / event / scheduled invocation
                 ↓
Module registry → Flow engine → Action runner → explicit result
                     ↓               ↓
                  RunStore       EventBus contract
                     ↓               ↓
                 PDO SQLite      realtime broadcaster → WebSocket clients

PHP RemoteAction ← JSON CVP/1 over HTTP → Python Worker
JavaScript / React Native ← HTTP + CVP/1 WebSocket → Corevia
```

| Directory | Responsibility |
| --- | --- |
| `packages/core` | Container, module registry, actions, flows, state, events, validation, result/store/logging contracts |
| `packages/http` | Routing, JSON, middleware, CORS, flow adapter, explicitly enabled action gateway |
| `packages/protocol` | CVP/1 validation/serialization and timeout-bounded remote action calls |
| `packages/persistence-pdo` | SQLite PDO run store, step logs, atomic idempotency reservations and retry locks |
| `packages/realtime` | WebSocket frames/server, rooms, private authorization, heartbeat, authenticated local IPC |
| `packages/cli` | Commands, diagnostics and generators |
| `sdk/js`, `sdk/python` | Independent public protocol clients and external worker |
| `app` | Orders, Payments, Notifications demo modules and idempotent demo ledger |
| `protocol` | Shared schema and valid/invalid fixtures |

Core never imports HTTP, PDO or realtime implementations. Modules register explicitly; registration is isolated and transactional in memory. Dependencies are booted first. A flow can only use actions owned by its module or explicitly declared dependencies. Duplicate IDs/public names, missing dependencies, disabled dependencies and cycles are reported per module. Unrelated healthy modules continue booting.

## Runtime API

```php
use Corevia\Core\{Corevia, Action, ExecutionContext, State, Flow, Module, ModuleContext};

final class Increment implements Action
{
    public function handle(State $state, ExecutionContext $context): State
    {
        $state->data['count'] = ($state->data['count'] ?? 0) + 1;
        return $state;
    }
}

final class CounterModule implements Module
{
    public function name(): string { return 'counter'; }
    public function dependencies(): array { return []; }
    public function register(ModuleContext $context): void
    {
        $context->actions(['counter.increment' => Increment::class]);
        $context->flows([
            Flow::define('count')->step('counter.increment')->critical(),
        ]);
    }
}

$app = Corevia::create(basePath: __DIR__);
$app->modules([CounterModule::class]);
$app->boot();
$result = $app->run('count', ['count' => 0], key: 'operation-1');
```

Steps accept a registered action name or registered action class. Class-based actions must still belong to the module/dependencies. `State` stores JSON-compatible data; subclass it for typed domain accessors, as in `CheckoutState`. Actions have a uniform `State` signature and may return the same state subtype or an `ActionResult`. Container bindings supply explicit dependencies; class constructors support recursive object autowiring.

```php
Flow::define('example')
    ->step('cart.validate')->critical()
    ->step('payment.charge')->critical()->retry(2, delayMs: 100, exponential: true)
    ->step('email.send')->nonCritical()->retry(5)
    ->step('audit.write')->when(fn (State $s) => $s->data['audit'] ?? false);
```

`retry(2)` means one initial attempt plus two retries. Only failures explicitly marked `retryable` are retried. Delay doubles when requested, bounded to 30 seconds. Unexpected exceptions become `ACTION_EXCEPTION`, are logged without exception messages, and are not retried automatically. Failed attempts discard state mutations; actions must separately protect side effects with `$context->operationKey('action.name')`. External providers must honor those keys.

Critical failures stop the flow; non-critical failures allow later steps. Every run contains state snapshots, per-step status/timing/attempts, errors, trace/request IDs and timestamps. Final status is `completed`, `completed_with_warnings` or `failed`. Conditions may skip steps; condition exceptions fail the flow. `running` is an intermediate persisted status returned for a concurrent duplicate request.

Idempotency keys are scoped to flow name. Identical input returns the existing run; changed input yields `IDEMPOTENCY_CONFLICT`. The hash uses PHP's JSON representation of the input, including key ordering. Use the same payload representation for repeated calls. Persisted idempotency lasts until explicitly removed from storage. Failed critical runs are not rerun under the same key.

See [API examples](docs/api.md), [protocol](docs/protocol.md), [deployment and limitations](docs/deployment.md), and [implementation analysis](docs/implementation.md).

## SDK examples

JavaScript package is provided locally under `sdk/js` and has not been published to npm:

```js
import { CoreviaClient } from './sdk/js/index.js';
const client = new CoreviaClient({
  baseUrl: 'http://127.0.0.1:8081',
  realtimeUrl: 'ws://127.0.0.1:8080/realtime',
  token: () => yourSessionToken,
});
client.onError(error => console.error(error.code));
const unsubscribeHandler = client.channel('orders').on('order.updated', (data, envelope) => {
  console.log(data, envelope.meta.trace_id);
});
// The demo gateway exposes only orders.get, and only when COREVIA_API_TOKEN is set.
const { data, requestId, traceId } = await client.call('orders.get', { run_id: 'run_...' });
client.disconnect();
```

Reconnect restores subscriptions. Event delivery is live; missed events are not replayed. React is optional: `sdk/js/react.js` exports `CoreviaContext` and `useCoreviaAction`. Standard fetch, AbortController and WebSocket implementations support React Native; no DOM, TextEncoder or Node Buffer is required by the client. Native push/background execution is outside V1.

```python
from corevia import Worker
worker = Worker('http://127.0.0.1:8090', token='development-shared-secret')

@worker.action('ai.analyze')
def analyze(payload):
    return {'characters': len(payload['text'])}

worker.run()
```

Use `PYTHONPATH=sdk/python` or install with `pip install ./sdk/python`. The Python package has no third-party runtime dependencies.

## Tests

```sh
composer validate --strict
composer lint
composer test
node --test sdk/js/test/*.test.js
PYTHONPATH=sdk/python python -m unittest discover -s sdk/python/tests -v
PYTHON=python node tests/integration.mjs
```

PowerShell:

```powershell
$env:PYTHONPATH = "$PWD\sdk\python"
$env:PYTHON = 'python' # Or the absolute path to your installed executable
python -m unittest discover -s sdk/python/tests -v
node tests/integration.mjs
```

Integration tests use dynamic loopback ports and isolated temporary storage. They start and stop actual PHP/Python/realtime processes, check SDK event delivery/reconnect, private channel authorization, persistent HTTP idempotency, critical failures and CLI retry. Shared CVP fixtures run in all three languages. GitHub Actions is configured for PHP 8.2, 8.3 and 8.4.

## CLI

```text
serve                         routes                       modules
flow:list                     flow:run <name> --input=file.json [--key=key]
flow:show <run-id>             flow:retry <run-id> <step-index>
realtime:start                realtime:stop                doctor
make:module <Name>            make:action <Name>           make:flow <Name>
```

Generators refuse existing files and require PascalCase names. Generated actions/flows live under `app/Generated`; register them explicitly. Generated modules are added to the bootstrap module list. Server host/port options use `--host=127.0.0.1 --port=8081` for HTTP and port 8080 for realtime.

MIT licensed. Contributions should preserve package boundaries, restrictive defaults and cross-language tests.
