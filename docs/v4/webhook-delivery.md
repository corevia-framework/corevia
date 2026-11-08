# Signed delivery, history and operator redelivery

Run `SqlOutbox::migrate()` during setup, outside application transactions. It now creates companion `_attempts` and `_operations` tables; existing outboxes need this additive setup before running the new relay. Keep the same namespace/prefix and back up all three tables together. This does not convert existing MySQL engines or migrate application schemas. Outbox table names are limited to 48 characters so companion names fit supported drivers.

```php
$outbox = new Corevia\Persistence\SqlOutbox($connection);
$outbox->migrate();
$delivery = new Corevia\Http\WebhookDelivery([
    'order.created' => [
        'url' => 'https://partner.example/hooks/corevia',
        'signature' => new Corevia\Http\WebhookSignature($independentKeyRing, 'current'),
    ],
]);
$delivery->relay($outbox, time()); // at most one outbound attempt
```

Endpoints are fixed, operator-owned configuration. Only predeclared topics resolve to destinations. HTTPS is required; query strings, credentials and fragments are refused. Event payloads never supply URLs. This is **not** a public arbitrary-subscriber URL service or a DNS-rebinding-safe user URL validator. Only use trusted operators' destinations; build any user-managed registration facility with a dedicated SSRF-safe transport/policy. The default transport verifies TLS, refuses redirects and has a ten-second timeout. Literal loopback HTTP is available only with both the delivery's explicit development flag and an appropriate development transport.

Bodies contain `id`, `type` and `data`. Headers include `Corevia-Event-Id`, `Corevia-Signature` and the stable `Idempotency-Key`. The signature covers raw body, timestamp and event ID; key rings support overlapping rotation. HTTP success means the configured transport completed a 2xx response, not proof of the receiver's application effect. Response bodies are discarded with a 64 KiB limit. No response content or destination credentials enter history. A custom `HttpClient` must preserve verified TLS, no redirects, bounded I/O and non-2xx rejection.

Relay claims and durable attempt intent commit together **before** network work. Normal application writes/events still commit in one application transaction. Each result records delivery/retry/dead/stale outcome and exception class, never the exception message or event payload. A claimed attempt whose process crashes remains uncertain; a later claim preserves that uncertainty in history. Owner/lease checks prevent a stale worker from acknowledging a newer claim. This remains at-least-once delivery: a crash between receiver effect and acknowledgment can redeliver. Receivers must deduplicate by event ID and reconcile uncertain effects.

```php
$history = $outbox->history($eventId, limit: 50); // at most 100 metadata rows
$accepted = $outbox->retryDead(
    $eventId, expectedAttempt: 6,
    requestId: bin2hex(random_bytes(16)), // persist once per operator action; reuse for retries
    now: time(),
);
```

Redelivery is an explicit operator action for dead events only. It requires the expected attempt count and a unique opaque request ID, preserving the original event ID/body and previous history. A duplicate operator request cannot enqueue again. Authorize/audit the operator action and reconcile the receiver before redelivery. There is no public redelivery route. Keep retry settings consistent across workers. History is bounded per query; records are not automatically discarded. Define archival/retention and coordinated backups for payload/history/operator-request tables before sustained deployment. Reusing a deleted operation ID after arbitrary database restoration is outside the deduplication guarantee.

`SqlReplayReservations` provides optional shared SQL reservation state for `WebhookSignature::verify`:

```php
$replay = new Corevia\Persistence\SqlReplayReservations($dedicatedConnection);
$replay->migrate();
$id = $signature->verify($rawBody, $header, $now,
    fn ($digest, $expires) => $replay->reserve($digest, $expires, $now),
);
```

Reservations are atomic, expire within one hour and are cleaned only by explicit bounded `prune()`. Keep the receiver's replay namespace scoped to its signing/event-ID domain. Reservation is **not** an application inbox transaction: a crash after reservation can prevent an automatic receiver retry. The receiver must own a durable idempotency/inbox and acknowledgment/reconciliation policy; do not acknowledge an uncommitted effect merely because a replay digest exists. The framework does not silently retry a receiver callback after an uncertain outcome.

`php tests/v4/webhooks.php` covers durable intent/history, fixed destination rules, key rotation, atomic replay reservation and stable-ID controlled redelivery. The disposable service suite runs the same contracts on actual MySQL/PostgreSQL. Existing reliability/concurrency tests cover rollback, crash recovery and exclusive active claims.

## Local multi-worker SQLite

The eight-process stress fixture provisions **only its own temporary database** in WAL mode on local disk, with a bounded busy timeout. Rollback-journal single-connection behavior is still covered by the other SQLite contracts. As history traffic increased, the rollback-journal stress run hit the configured busy limit; it is not recorded as a pass. WAL concurrency then passed with the unchanged ownership/quota assertions. Configure/measure local-disk WAL and busy timeouts explicitly for concurrent SQLite workers; no framework connection silently changes application journal settings. Network filesystem SQLite and multi-host SQLite are not supported coordination deployments. Use a dedicated MySQL/PostgreSQL service for multiple hosts. Service throughput and worker soak acceptance remain release gates.
