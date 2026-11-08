# Private operations, telemetry and work budgets

These capabilities are opt-in. No debug route, collector, database or runtime service is added to an application automatically. Existing generated auth files are untouched. The ordinary PHP/JSON site remains database-free.

## Instrument registered operations

```php
$telemetry = new Corevia\Core\Telemetry('orders', [
    'http.orders' => 'server', 'orders.stream' => 'internal', 'inventory.read' => 'client',
]);
$router->get('/orders', $controller)->middleware(
    new Corevia\Http\RequestTelemetry($telemetry, 'http.orders', maximumMs: 5000),
);
$result = $telemetry->span('inventory.read', function ($trace) use ($client) {
    return $client->read();
});
```

Only pre-registered static operation names and span kinds are accepted. Span records contain trace/span/parent IDs, operation, timing and exception class; error messages, URL paths/parameters, headers, account IDs, SQL text/bindings, prompts and model output are excluded. Do not put application data into operation names. Sampling is deterministic by trace ID, can only reduce an upstream sampled decision, and is bounded by `samplePerThousand`. The buffer holds at most 1024 spans (default 256), with a dropped counter. At most 128 registered operations create bounded metric labels. Drain after each request or bounded worker batch; otherwise long-lived buffers stop retaining spans at capacity.

`snapshot()` returns a bounded in-memory view. `prometheus()` emits operation counts and accumulated duration with static labels. Those counters belong to the current object/process and reset at drain; a PHP-FPM request cannot scrape another request's memory. For short-lived PHP requests, push delta metrics to a collector rather than relying on a separate HTTP scrape. Persistent workers may expose their own current metrics behind an operations proxy.

```php
$exporter = new Corevia\Http\OtlpTraceExporter(
    new Corevia\Http\NativeHttpClient(timeout: 2),
    'https://collector.internal.example/v1/traces',
    headers: ['Authorization' => 'Bearer '.$collectorToken],
    metricsEndpoint: 'https://collector.internal.example/v1/metrics',
);
$exporter->export($telemetry);
```

Export is optional OTLP/HTTP JSON: trace IDs are hex, enums numeric and nanosecond timestamps/64-bit counters decimal strings. Delta metric batches contain counts and duration sums, without request-derived labels. See the [OTLP specification](https://opentelemetry.io/docs/specs/otlp/). The exporter drains once, limits the collector response to 64 KiB and accepts empty/default-valued partial-success messages for Collector compatibility while rejecting nonzero rejection counts, warnings and malformed partial results. It does not automatically retry or grow an offline queue. A failure can lose some/all of the batch; a metrics success followed by trace failure is not an atomic export. Catch exporter failures at the application's delivery boundary so telemetry availability does not replace a business response. Configure verified HTTPS, trusted collector endpoints and appropriately bounded timeouts. No external collector is contacted by the default tests; actual backend interoperability/deployment needs acceptance.

## Private health and development inspection

```php
Corevia\Http\OperationsRoutes::mount(
    $router, $app, $telemetry,
    authorize: fn ($request) => $operationsAuthorization->allows($request),
    readiness: ['inventory' => fn () => $inventory->ping()],
    environment: $effectiveEnvironment,
    queries: fn () => $queryDiagnostics->snapshot(),
    jobs: fn () => $queue->status(),
);
```

The authorization service above is application policy, not a framework-created credential. Every request requires explicit authorization; denial or an authorization exception returns 404. No public health bypass is installed. Put these endpoints behind private network/proxy controls as appropriate. Readiness includes the kernel state plus strictly boolean named checks; exceptions produce `false`, without their messages. Liveness and readiness are separate; neither grants release readiness. Checks must use bounded adapters.

`/_corevia/live`, `/_corevia/ready` and `/_corevia/metrics` return `no-store` responses. `/_corevia/inspect` is mounted only for the explicit `development`/`testing` environment, never for `production` (the default). Pass the real effective environment. It lists bounded static route/flow declarations, query fingerprints/counts/durations, aggregate job counts and the redacted telemetry buffer. It does not inspect arbitrary files, run payloads or execute debug commands. `QueryDiagnostics` now bounds unique fingerprints (default 1000) and reports dropped patterns; it never includes raw SQL or bindings. Query counters are per instance; request-scoped configuration is recommended.

## Propagate budgets and cancellation

```php
use Corevia\Core\{Deadline,WorkContext};
WorkContext::run(new Deadline(5000), function ($context) use ($remote) {
    $context->check();
    return $remote->call('inventory.read', []);
});
```

`WorkContext` is Fiber-local and always restored on exit. Nested work can shorten a parent budget but cannot extend it. Cancelling a parent `Deadline` invalidates all nested contexts. `RequestTelemetry` accepts `X-Corevia-Timeout-Ms` only as a reduction of its server-configured maximum. A streamed response resumes the captured parent/deadline chain at send time; use a separately registered `streamOperation` for stream timings. Stream producers still own resource scopes and appropriate post-header error handling.

`NativeHttpClient`, `RemoteAction` and `ProcessTransport` consume the current budget. CVP/2 propagates a relative timeout, an absolute `deadline_unix_ms` and optional W3C version-00 `traceparent`. The absolute time reduces the receiver's remaining work after transport/queue delay; keep server clocks synchronized. A badly skewed future deadline cannot extend the receiver's configured/relative cap; a past deadline rejects work before execution. CVP/1 keeps its existing worker behavior; it does not acquire an implicit remote action deadline. No protocol version bump is performed.

PHP shared-job handlers run inside a budget shorter than their claim lease; scheduler/outbox handlers inherit their explicit lease-bounded budget. They must still use stable effect keys, transport timeouts, process supervision and fenced application writes. A budget ending after an uncertain external effect never proves the effect did not happen.

```python
from corevia import Client, Deadline, Telemetry, Worker, WorkContext, work_context

telemetry = Telemetry('python-inventory', {'python.action': 'server'})
worker = Worker(telemetry=telemetry)

@worker.action('inventory.read')
def read_inventory(payload):
    context = WorkContext.current()
    context.check()
    return adapter.read(timeout_ms=context.remaining_milliseconds())

with work_context(Deadline(5000)):
    result = Client('http://127.0.0.1:8090', version='2.0').call('inventory.read', {})
```

The Python adapter above is application-owned. Context variables isolate threads/async contexts; execution cleanup runs on completion, rejection and expiry. The optional Python telemetry buffer emits matching OTLP JSON through `drain()` for an operator-owned exporter. Client redirects are rejected; bounded reads tighten socket timeouts and check the total monotonic budget. The tightened reader uses CPython's standard HTTPResponse socket layout and fails closed if it cannot enforce the read deadline. Test other Python interpreter/transport implementations before advertising support.

Cancellation is cooperative. Cancellation **before dispatch** prevents remote work. Once dispatched, caller cancellation stops locally at the next cooperative check/read boundary; a remote worker cooperatively stops at its transmitted deadline. There is no live authenticated cancellation RPC or forcible thread termination in this increment. Blocking DNS/native extensions or callbacks that ignore deadlines still need supervision. Do not claim a remote side effect was cancelled solely because its caller disconnected.

## Evidence

`php tests/v4/observability.php` checks redaction, bounded buffers, OTLP trace/metric shape, private routes, production inspector absence, streaming and Fiber cleanup. `python tests/v4/cross-runtime.py` uses actual loopback PHP/Python HTTP and stdio workers to verify budgets, parent/child spans, expiry and cleanup. `python tests/v4/outbound-http.py` checks slow response bodies, total read budgets and redirect rejection. SDK unit tests include nested cancellation/thread isolation. These do not replace real collector/runtime/cloud acceptance or independent security review.

## Actual collector fixture

On a private Linux acceptance host, `python tests/v4/collector.py --binary=/private/otelcol --output-root=/private/corevia/storage` explicitly runs an installed official Collector with loopback OTLP/HTTP, disabled internal telemetry listeners and bounded memory. No service is installed. It validates config, actual PHP span/metric ingestion, trace correlation and private-message omission, stops its own process group, then checks a disconnected collector fails within the adapter timeout without a hidden resend buffer. The receipt records binary/version/source/test hashes. Install and verify the selected official release checksum separately; the test does not download executables. TLS/authenticated collectors, downstream persistence, partial network partitions and sustained collector outages need separate acceptance.

The real v0.165.0 Collector emits `{"partialSuccess":{}}` on full acceptance. V4 now tolerates that empty protobuf message and explicit zero/empty defaults; actual rejection counts or warning text still fail visibly without automatic retry. See [OTLP response semantics](https://opentelemetry.io/docs/specs/otlp/) and the [official Collector releases](https://github.com/open-telemetry/opentelemetry-collector-releases).
