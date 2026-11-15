# Bounded retries and shared circuit breakers

`RetryExecutor` wraps a buffered operation with explicit retry safety, a transient-failure classifier, exponential full jitter and a cooperative deadline. `SqlResilience` supplies atomic, cross-process circuit and retry-budget state using optional SQLite, MySQL/InnoDB or PostgreSQL. It does not automatically wrap requests, mail, payments or model streams.

```php
$gate = new Corevia\Persistence\SqlResilience(
    $dedicatedConnection, 'inventory-api',
    failureThreshold: 5, cooldownSeconds: 30, probeSeconds: 60,
    retryCapacity: 100, retryWindowSeconds: 60,
);
$gate->migrate(); // operator setup
$runner = new Corevia\Core\RetryExecutor($gate, attempts: 3);
$result = $runner->run(
    function ($attempt, $deadline) use ($adapter) {
        return $adapter->readInventory(timeoutMs: $deadline->remainingMilliseconds());
    },
    new Corevia\Core\Deadline(5000),
    retrySafe: true,
    transient: fn ($error) => $error instanceof TemporaryInventoryFailure,
);
```

`TemporaryInventoryFailure` and the adapter above are application types. A deadline cannot force an arbitrary callback to stop. Use actual adapter transport timeouts and process supervision. Do not replay a partially emitted stream. `retrySafe: false` still uses the circuit but performs at most one operation. A classifier returning false also prevents a retry. Only explicitly retry-safe uncertain effects should be retried, using stable downstream idempotency keys.

All operation exceptions count toward the circuit; put ordinary input validation outside the wrapper. On threshold, the circuit opens for the cooldown. Afterward one half-open probe is admitted, with a bounded probe lease. A crashed probe can be replaced after expiry. Generations fence late successes/failures so an older attempt cannot close a newer open circuit. Permits are single-completion, in-process objects and cannot be serialized. State-store failures fail closed before an operation or stop further retry; a successful external effect followed by a state-write failure needs application reconciliation.

The retry budget counts only extra attempts, across all processes sharing the destination and prefix. It is a fixed-window quota, so bursts may occur on either side of a boundary. Reservations are conservative and never refunded, including cancellation or an opening circuit that prevents the next call. Initial calls need a separate application rate limit if required. Backoff checks cancellation at most every 50 ms; blocking adapter calls remain cooperative. Limits are 1–10 total attempts and at most 60 seconds for one backoff.

The SQL adapter uses the database clock by default. It rejects clock regression and configuration drift for an existing destination. All hosts must share the policy. Change policies through an explicit drain/reconciliation procedure; no automatic state reset is supplied. Database restoration/rollback can restore old quota/circuit state, so restored environments need operator reconciliation. `status()` returns state, failure count, remaining retry units and the window boundary, without operation input or exception messages. Shared state is coordination data rather than a durable delivery ledger.

Local evidence includes stale-result and probe-expiry tests, policy/clock rejection, non-retry-safe failures, exhaustion, cancellation and eight-process races proving one active recovery probe and a bounded shared retry quota. Run `php tests/v4/resilience.php` and `python tests/v4/concurrency.py`. Real-driver acceptance is available through the explicitly enabled [service suite](phase-g-acceptance.md). Cross-runtime deadline/cancellation propagation and hosting/provider outage drills remain open Phase E work.
