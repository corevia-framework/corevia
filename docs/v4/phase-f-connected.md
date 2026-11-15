# Phase F: budgets and synchronization increment

Release update: this file records the development increment. The current V4.0 disposition is in the [final scope review](final-review.md), [release notes](release.md) and [deferred-target guide](optional-validation.md). Earlier pending statements are historical, not a claim that unverified targets later passed.

Phase F is in progress. These optional SQL-backed primitives work with local fixtures; no model/vector-provider, mobile-platform or AI frontend acceptance is claimed.

## Tenant usage reservations

```php
$budget = new Corevia\Persistence\SqlUsageBudget($dedicatedSql, capacity: 100000);
$budget->migrate(); // operator setup
$provider = new Corevia\Ai\BudgetedProvider($configuredProvider, $tenantScope, $budget);
// Inject $provider into AiClient; its existing message/stream bounds still apply.
```

Budget capacity is trusted server configuration. Each fixed UTC/epoch window is separate by tenant. The budget transaction serializes reservations before provider execution and rejects overspending. The wrapper conservatively charges JSON input bytes plus the requested output units; these are application units, not actual token usage or provider currency. Reserve capacity according to the application's business model. A provider error, disconnect or cancellation keeps its reservation because billing/effects may be uncertain. Changing an active capacity requires operator reconciliation. Use a dedicated connection, and do not hold the budget transaction during inference. Shared-host cron/other workers must use the same authoritative database and server time policy. Old buckets need an operator retention policy; no automatic deletion is shipped here.

## Authenticated change feed

`SqlSyncStore($sql, $independentPrivateCursorKey)` is a dedicated bounded JSON record store with an append-only change feed. It does not magically synchronize existing arbitrary application tables. Run `migrate()` during setup. Every operation takes a server-authorized `TenantScope`.

```php
$sync->mutate($scope, 'note-1', expectedRevision: 0, data: ['title' => 'First']);
$page = $sync->changes($scope, cursor: null, now: time(), limit: 100);
$next = $sync->changes($scope, $page['cursor'], time(), 100);
$sync->mutate($scope, 'note-1', expectedRevision: 1, data: null); // tombstone
```

Creates require revision 0; each accepted mutation increments the record revision. A stale write fails with `SYNC_REVISION_CONFLICT`; refetch and ask the application/user how to merge. No silent last-write-wins merge occurs. Tombstones retain revision history, preventing a stale client from recreating a deleted record at revision 0. Data and feed events commit in the same transaction. Cursor signatures bind tenant, actor, position and a 24-hour expiry. Membership/session authority is checked again even for a valid cursor. A cursor ahead of retained history after a backup restore fails with `SYNC_CURSOR_REQUIRES_RESET`; reconcile from a fresh feed rather than silently skipping changes. Resume while `has_more` is true; after expiration, replay from the start and reconcile using record revisions.

The reference implementation caps records at 16 KiB, pages at 500 events and retained history at one million events per tenant. It deliberately retains history/tombstones; never prune rows arbitrarily or reuse sequence numbers. Capacity/retention changes and resetting clients require an explicit migration. Cursors are authenticated but readable: keep them private. Client transport endpoints still need bearer scopes, input limits, HTTPS and appropriate error mapping. This is not a browser-cookie authentication shortcut. Do not store bearer credentials in an offline data queue.

## Evidence and remaining work

`php tests/v4/connected.php` verifies budget denial before provider calls, separate tenant windows, cursor resume/expiry/tampering/tenant/actor binding, optimistic conflicts, deletions and access revocation. `python tests/v4/concurrency.py` races separate SQLite processes against the same budget and record revision.

The [AI platform](ai-platform.md) now implements knowledge revisions, contract output, Qdrant service search, bounded retrieval metrics, permissioned tool execution/human review and the refreshed AI page. [Offline/mobile synchronization](offline-sync.md) now supplies bounded JS/Dart queues, actual PHP API transports, lost-ack receipts, explicit conflicts and native UI page examples. Remaining work includes real model/provider, native device/storage, OS tool isolation, sustained load/security and hosting acceptance. Existing V3 AI features remain available; these additions do not certify untested providers.

## Idempotent offline mutation increment

`SqlSyncStore::mutateOnce()` adds an actor/tenant-bound receipt in the same transaction as each record/feed write; identical retries return the original acknowledgment. `SyncRoutes` exposes an opt-in v1 API only with an application-provided scope resolver and explicit read/write capabilities. The two SDKs share format-1 queue snapshots, CAS storage ports, bounded batches and explicit conflict/rebase semantics. Tokens are looked up per request and never added by the SDK to offline snapshots. Actual JS/Dart/PHP loopback tests exercise lost acknowledgments, restart, interop, tombstones and current authorization. See the [guide](offline-sync.md) for limits and the distinction between memory/test file adapters and production durable storage.
