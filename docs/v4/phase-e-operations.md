# Phase E: reliable delivery increment

Phase E is in progress. This increment adds an explicit SQL outbox, webhook signature codec and cooperative deadline. It does not complete full operations/cloud acceptance.

## Commit application changes and events together

```php
$outbox = new Corevia\Persistence\SqlOutbox($db);
$outbox->migrate(); // operator setup, outside an application transaction
$result = $outbox->commit('order.created', ['order_id' => $id],
    function ($connection) use ($id) {
        $connection->table('orders')->insert(['id' => $id]);
        return $id;
    }, time());
```

The callback must write through that connection, using transactional tables. Do not perform DDL, commit manually, contact an external service or write another store inside it. Nested application transactions are supported; no outbound delivery occurs until the owning transaction commits. SQLite/PostgreSQL and MySQL InnoDB are intended drivers. Existing MySQL tables must use InnoDB; schema creation is not a conversion of existing tables. Local evidence currently covers SQLite.

A relay uses a dedicated connection and calls `relay($sender, $now, leaseSeconds: 60, maxAttempts: 6)`. The sender receives `event_id`, `topic`, `data` and `attempt`. It must use the stable event ID for downstream deduplication and finish within its lease with real transport timeouts. Database owner fences prevent a replaced worker from acknowledging another worker's delivery. A crash/timeout after the remote side effect can still produce redelivery. Failed attempts back off exponentially, with bounded attempts and a dead state. `status()` exposes counts without payloads. Configure supervision, retention, alerting and operator reconciliation before deployment. Metadata delivery history and explicit operator redelivery are now implemented; see [webhook delivery](webhook-delivery.md). There is no public retry route or administration UI.

`WebhookSignature` signs raw bodies with a key ID, timestamp and opaque event ID. A key ring supports overlapping verification during rotation. Verification checks the signature/time window, then calls an application-supplied atomic replay reservation. The callback must reserve the digest once until its expiry and return true only for the first delivery. An in-memory map is a test fixture, not a multi-host replay defense. Decide how application processing/acknowledgment reconciles a crash after reservation. Delivery URLs, HTTPS, SSRF protection, retry policy and secret provisioning remain the host application's responsibility; this codec never initiates a network request.

`Corevia\Core\Deadline` uses a monotonic clock, cancellation and remaining milliseconds. Pass remaining time into each blocking adapter. It cannot forcibly interrupt a blocking operation or supervise another process. Shared SQL retry budgets and circuit breaking are now implemented; see [resilience](resilience.md). Cooperative PHP/Python CVP/2 budget propagation is now implemented; live authenticated remote cancellation remains open. See [work budgets](observability-and-budgets.md).

## Evidence and remaining work

`php tests/v4/reliability.php` checks rollback, stable retry IDs, claim expiry/recovery, webhook tampering/expiry/replay/rotation and deadline/cancellation behavior. `python tests/v4/concurrency.py` races eight independent SQLite clients for active outbox claims. This demonstrates local ownership semantics, not exactly-once external delivery.

The later increments below implement private inspection/export, delivery history/redelivery and cooperative cross-runtime budgets. Still open: live authenticated remote cancellation, actual collector/outage drills, hosting presets/acceptance and worker soak/load. No live service, existing site or outbound email was used by this increment.

## Scheduler and recovery increment

The [SQL scheduler](scheduler.md) now supplies explicit cron/timezone/DST rules, seven-day atomic dispatch reservations, overlap skipping, cooperative deadlines, scoped cleanup and stale-owner fencing. Queue metadata inspection and explicit dead-job retry preserve stable effect IDs. [Shared circuit breakers and retry quotas](resilience.md) bound retries and allow one fenced recovery probe. These additions have local contract and eight-process SQLite race coverage; the source-bound service receipts record actual driver results separately.

## Instrumentation and delivery increment

[Private operations and budgets](observability-and-budgets.md) now provide registered redacted spans, bounded metrics, optional OTLP export, private health/readiness and development-only inspection. [Webhook delivery](webhook-delivery.md) adds durable attempt intent/history, fixed signed destinations, shared replay reservations and controlled operator redelivery. Actual loopback contracts cover PHP/Python HTTP/stdin trace/budget correlation and slow-stream limits. Cloud presets/hosting, actual collector acceptance, live remote cancellation and worker soak/load remain unaccepted.

Reviewable [hosting recipes and bounded queue batches](hosting-presets.md) are now implemented. Isolated routing and short worker cleanup fixtures are separate from actual platform/sustained-load acceptance.
