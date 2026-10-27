# Flow V2

V1 flow definitions continue working. New fluent policies attach to the last action:

```php
Flow::define('order')
    ->step('reserve')->outputs(['reservation_id'])->compensate('release')
    ->step('charge')->retryPolicy(3, 100, true, [TemporaryFailure::class])->timeout(2000)
    ->waitFor('payment.settled')
    ->approval('fulfillment')
    ->step('ship')
    ->step('notify')->nonCritical();
```

`retryPolicy` counts all attempts; V1 `retry` counts retries after the first attempt. Exception retries require an explicit exception-class allowlist; business results must mark their own error retryable. Backoff is exponential with optional full jitter and a 30-second delay cap. Failed candidates cannot change the committed state. Actions with external side effects must provide their own idempotency using the stable execution context IDs. Local deadlines measure elapsed time and reject late output; they cannot interrupt arbitrary blocking PHP code. Timeout errors never automatically retry. Use bounded HTTP or process transports for hard I/O/process deadlines.

Critical failure runs declared undo actions for previously completed steps in reverse order. Every compensation attempt is stored independently; one compensation failure does not skip the remaining undo actions. Overall status remains failed with the original error. Non-critical failures preserve valid work and produce completed_with_warnings. External systems need idempotent undo operations. A crash can leave a running or compensating record uncertain: inspect it and reconcile the external effect instead of replaying it automatically.

`pause`, `waitFor`, and `approval` persist a checkpoint and return `paused`. Resume explicitly through an authorized application route or trusted CLI with `$app->resume($runId, $eventName)`. Plain pause and approval use no event argument. The runtime never approves a human checkpoint automatically. Resume takes an exclusive run lock, skips completed steps, and checks the saved definition fingerprint. Conditions are application code: deploy compatible condition behavior for paused runs or use a new flow name. Concurrent or duplicate resume calls are rejected. Event delivery must resolve the intended run ID in application code; the event bus does not scan all stored flows.

Use `JsonRunStore` for a small durable database-free application:

```php
$store = new JsonRunStore(__DIR__ . '/storage/runs.json');
$app = Corevia::create(__DIR__, store: $store);
```

Keep storage private and set directory ACLs appropriately. The store coordinates local processes using file locks and caps the file at 16MiB. SQLite PdoRunStore remains available for larger single-node installations. Distributed leases need a custom adapter; local files are not a distributed database.

`parallel(['lookup.price', 'lookup.stock'])` requires an explicitly bound `ParallelExecutor`. Core never silently substitutes serial work or creates infrastructure. The adapter runs isolated tasks and returns ordered results. Core merges only disjoint top-level state changes; conflicts or branch failures prevent the group from committing state. Parallel branches should perform reads or independently idempotent work because external effects cannot be atomically rolled back. Per-branch compensation and distributed transactions are not provided.

Inspect `timeline`, `steps`, `next_step`, `checkpoint`, and `compensations` in the persisted run. Run `php -n tests/v2/flows.php` for database-free failure and persistence checks.

Try the durable example with `php -n examples/v2/workflow.php start`. It returns a paused run ID. Resume it using `php -n examples/v2/workflow.php resume <run-id>` in a separate process. Start with `start --fail` to see the reservation compensated after approval and a simulated delivery failure. This local CLI represents a trusted operator; a real approval endpoint must check the user's permissions.
