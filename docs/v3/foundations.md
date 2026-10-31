# V3 foundation guide

These APIs are included in the V3 release-preparation branch. Existing V2 apps can keep their bootstrap and `modules()` calls. See [production acceptance](production-readiness.md) for release status and validation limits.

## Explicit composition

In your application bootstrap, register modules explicitly before boot:

```php
$app = Corevia\Core\Corevia::create(__DIR__);
$app->compose([new WebModule(), new AuditModule()]);
$app->boot();
try {
    $graph = $app->graph();
} finally {
    $app->shutdown();
}
```

Dependencies boot first. Independent modules and dependency names use stable lexical ordering, so reversing the input module list produces the same plan. Missing/disabled dependencies, cycles and missing capabilities fail before any module registers. Runtime module-hook failures retain V2's failure isolation and cleanup. Canonical composition snapshots declared manifests/dependencies; modules should make registration deterministic and obtain environment settings through configuration.

`modules()` retains the V2 registration-order behavior. Choose `compose()` for new applications. The bundled database-free web bootstrap enables canonical composition for the artifact commands, or when `COREVIA_COMPOSITION=canonical` is set. It currently registers no application modules, so its module order is an empty list until you add your modules.

```sh
php corevia app:graph
```

The graph records enabled/disabled modules, dependency order, declared action/flow/event registrations, service lifetimes, health and capabilities. Anonymous class metadata uses source-derived identities instead of machine-specific absolute paths; ambiguous anonymous binding identities fail compilation and should be replaced with named classes. The graph does not introspect arbitrary live object state or factory captures.

## Per-unit services and cleanup

Use a singleton for intentionally shared process resources. Use a scoped service for data/resources belonging to one execution unit:

```php
use Corevia\Core\Disposable;

final class RequestCache implements Disposable
{
    public array $items = [];
    public function dispose(): void { $this->items = []; }
}

$app->container->scoped(RequestCache::class, RequestCache::class);
```

Constructor injection returns the same cache inside one unit and a new cache in the next unit. `ExecutionScope` is also injectable. Resolving a scoped service outside a scope fails; a singleton cannot capture one during construction. Disposable services close automatically. A third `scoped()` argument can provide a custom disposer for resources that do not implement `Disposable`.

The router owns an HTTP scope, queue processing owns one scope per job, and realtime handling owns one scope per decoded websocket message, not per connection. Direct flow/action calls create a scope when none exists. Flow actions within an HTTP request or job share that parent's scope. Flow CLI execution uses a CLI scope; the persistent worker/realtime loops do not hold one scope open for their whole lifetime.

Custom adapters and scheduled tasks can define a unit explicitly:

```php
$app->scope('job', function (Corevia\Core\ExecutionScope $scope) use ($app) {
    $scope->onClose(static function (): void { /* release a resource */ });
    $app->run('orders.process', ['order_id' => 42]);
}, ['job_id' => 'example-42']);
```

Cleanup runs in reverse acquisition order even when work fails. Cleanup errors do not replace the original work exception; a cleanup-only failure fails the unit. Nested scopes restore their parent, PHP Fibers have isolated scope stacks, and application shutdown refuses active units. Avoid putting secrets in scope metadata or retaining scoped objects in process globals. Buffered HTTP and `Response::events()` streaming are covered. Stream producers acquire their scope/resources at send time; custom async adapters must keep a scope open until their own work completes.

Python workers expose the same per-dispatch pattern:

```python
from corevia import Worker

worker = Worker()

@worker.on_scope
def prepare(scope):
    scope.service('cache', dict, lambda cache: cache.clear())

@worker.action('demo.count')
def count(payload):
    cache = worker.service('cache')
    cache['count'] = payload['count']
    return {'count': cache['count']}

worker.run()
```

`on_startup` and `on_shutdown` register process-resource hooks, receiving the worker. Normal shutdown drains request threads before releasing shared resources; shutdown hooks unwind in reverse order, including partial startup failure. Per-dispatch `on_scope` hooks receive a scope; thread-local context is restored and cleanup runs before a reply. Hook failures return correlated safe protocol errors. A model loaded once for the worker belongs to process startup/shutdown, while request buffers belong to the dispatch scope. These hooks do not implement model hosting or automatic GPU memory management.

## Private application artifacts

```sh
php corevia app:compile
php corevia app:verify
php corevia app:inspect
```

The profile-aware runtime bootstrap boots canonically for compilation (Web by default). Defaults are `storage/application.build.json` and a separately generated `storage/build-signing.key`. Both stay in ignored storage. `--output=...` and `--key-file=...` select deployment-specific paths. Use `APP_ENV` and the same configuration environment for compilation and verification. See [runtime profiles](profiles.md) for API/worker/realtime selection.

Identical graph, effective config, source bytes, framework version, environment and signing key produce identical artifact bytes. The fingerprint uses HMAC-SHA256. Key rotation requires recompilation. `app:inspect` prints a verified summary without configuration values; the artifact itself contains private configuration, so restrict its directory and key using your deployment's filesystem permissions/Windows ACLs.

Default source inputs are existing `app/`, `packages/`, `config/`, `routes/`, `contracts/`, `autoload.php`, `composer.json` and `composer.lock`. All regular files in declared directories are hashed; symlinks and roots outside the app are rejected. For a custom bootstrap, use `ApplicationArtifact::build()`/`verify()` with an explicit complete source list, including any additional templates or SDK code your build needs. The bundled CLI targets `config/runtime.php`, which selects the appropriate adapter bootstrap, rather than the legacy checkout demo.

To boot the bundled starter using compiled configuration in PowerShell:

```powershell
$env:COREVIA_BUILD_ARTIFACT = "$PWD/storage/application.build.json"
$env:COREVIA_BUILD_KEY_FILE = "$PWD/storage/build-signing.key"
php corevia serve
```

The bootstrap verifies integrity, environment/framework, effective configuration, registrations and declared source drift before handling requests. A mismatch fails boot and cleans up acquired application resources. This executes trusted PHP bootstrap code to reconstruct the graph; it is not a replacement for trusted deployments. It does not serialize live objects, compile machine code, prove dynamic factory semantics, or cover undeclared external files/environment values. Recompile after changing covered inputs. Clear the artifact environment variables before replacing a stale build, or it will correctly reject its own stale bootstrap.

## One application contract, four runtimes

Start with `contracts/example.json`. It declares application actions, input/output definitions, events, named errors and auth requirements. CVP transport envelopes remain separate from these business payload contracts.

```sh
php corevia contract:compile contracts/example.json --output=contracts/generated
php corevia contract:compile contracts/example.json --output=contracts/generated --check
```

Outputs include the canonical manifest/fingerprint, PHP client factory, JS runtime and TypeScript declarations, Python typed action methods, and Dart value wrappers/typed methods. They have no generated third-party runtime dependencies; PHP bindings use Corevia's protocol package. React uses the JS client and Flutter uses Dart. Regeneration refuses a nonempty unowned directory or hand-edited generated files. Commit your manifest and generated outputs together; `--check` rejects missing/stale output without writing.

For example, import the generated JS client:

```js
import {createClient} from './contracts/generated/js/client.js';
const api = createClient(async (name, payload, requirements) => {
  // Replace with your transport/auth implementation; return raw business data.
  return {message: `Hello ${payload.name}`};
});
const greeting = await api.action('demo.greet', {name: 'Ada'});
```

The transport callback receives action name, payload and auth requirements. Adapt your existing CVP client by unwrapping successful replies and propagating failures before returning business data. The generated client validates input before transport and validates returned data. Auth metadata describes requirements; the server must actually enforce them.

The PHP gateway can opt in to the manifest:

```php
$contracts = Corevia\Protocol\ContractManifest::load(__DIR__.'/contracts/example.json');
$router->actionGateway(['demo.greet'], $authorize, $contracts);
// $authorize(Request $request, string $action, array $requirements): bool
```

Keep the explicit action allowlist. Supply a real authorization callback that checks the manifest requirements. Invalid input returns `CONTRACT_INPUT_INVALID` (400); incompatible output or undeclared error returns `CONTRACT_OUTPUT_INVALID` (500). Example actions are not automatically registered or exposed by compiling their manifest.

Supported schemas are acyclic local `$defs` references, object/array/string/integer/number/boolean/null (including type unions), properties/required, boolean `additionalProperties`, array items, scalar enums, numeric bounds, string lengths and array lengths. Objects reject extras by default. Unsupported keywords such as `pattern`, external references and recursive types are rejected at compilation. Named schemas use PascalCase identifiers; generated action/property name collisions are rejected. Portable integers stay within ±9007199254740991; lengths count Unicode code points. Union types may use dynamic representations in Dart. PHP callers should use `new stdClass()` for an empty JSON object; `[]` represents an empty JSON array.

## Run the phase checks

```sh
php corevia test
node tests/v3/contracts.mjs
python tests/v3/contracts.py
dart analyze contracts/generated/dart
dart run tests/v3/contracts.dart
npm exec --yes --package=typescript@7.0.2 -- tsc tests/v3/contracts.types.mts --noEmit --strict --module NodeNext --moduleResolution NodeNext --target ES2022
```

The PHP foundation tests also run with `php -n`; they need JSON, not a database. Python binding generation targets Python 3.11+. The PHP, SDK and live integration suites remain the regression gate; see the root README and CI workflow.
