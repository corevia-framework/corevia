# Optional offline/mobile synchronization

The JS and Dart clients now have a bounded offline queue, explicit conflicts and resumable feeds. They use the optional SQL sync reference store; no queue, database, public route or mobile dependency is enabled for ordinary applications. The SDK versions remain the current development baseline until final V4 release/publication.

## Server setup and current authority

```php
$sync = new Corevia\Persistence\SqlSyncStore($connection, $independentCursorKey);
$sync->migrate(); // operator setup, not per-request DDL
[$feed, $mutations] = Corevia\Http\SyncRoutes::mount(
    $router, $sync,
    resolve: fn ($request) => $tenantAccess->resolve(
        $request->attributes['api_identity'], $serverValidatedTenant,
        $request->method === 'GET' ? 'sync.read' : 'sync.write',
    ),
);
$feed->middleware(new Corevia\Auth\ApiTokenMiddleware($tokens, 'sync.read', $serverValidatedTenant));
$mutations->middleware(new Corevia\Auth\ApiTokenMiddleware($tokens, 'sync.write', $serverValidatedTenant));
```

Resolve the tenant from existing server application policy, not an unchecked request field. Each route needs its matching bearer scope and current tenant authorization. `SyncRoutes` requires a resolver and checks `sync.read`/`sync.write`; no route is installed automatically. A resolver failure returns 403. Add application-specific record/schema/ownership rules in your own controller/store wrapper when needed. The reference store is a **shared tenant feed**, not an automatic per-record owner policy. A user with tenant read authority can read that tenant's records; choose a personal-tenant model or add a narrower store if that is not your application policy.

`GET /api/v1/sync/changes` accepts an optional opaque cursor and a limit of 1–100. `POST /api/v1/sync/mutations` accepts only `operation_id`, `id`, integer `expected_revision` and JSON record `data` (`null` means deletion). API results use the existing `data`/`error` envelope and no-store responses. CSRF/browser-cookie authentication is not substituted for the bearer model. Transport HTTPS and appropriately configured trusted proxy behavior remain deployment requirements.

## Retry one operation safely

`mutateOnce($scope, $operationId, $id, $expectedRevision, $data)` commits the record, change-feed event and actor/tenant-bound retry receipt together. Operation IDs are 32 lowercase random hex characters (128 bits). The exact same operation ID/input returns the original acknowledgment after an uncertain/lost response, without another mutation. Reusing it with changed input fails `SYNC_OPERATION_CONFLICT`; stale record versions fail `SYNC_REVISION_CONFLICT`. Authority is checked again even when serving a stored receipt. Receipts from another actor/tenant are not accepted as the same operation.

The acknowledgment includes operation ID, record ID, new revision, feed sequence and deletion state. It is evidence of the original operation, not necessarily the latest state if other devices wrote afterward; follow the feed to reconcile. Portable expected revisions are 0 through 9007199254740990. Existing V3/V4 low-level `mutate()` behavior remains available and does not gain idempotency automatically.

Existing sync installations need the additive `_operations` table from `migrate()`. Back up records, events, receipts and cursor key as one matching snapshot. Receipt/event capacities are one million per tenant; no automatic receipt pruning or sequence reuse is supplied. Archive/reset requires a deliberate migration and all affected offline clients to reconcile. Independent database rollback can reintroduce old receipts or remove a committed receipt; these guarantees do not span arbitrary restore points.

## JS / React Native

Import from the local V4 SDK dependency (`@corevia/js/sync` once that development package is linked/installed):

```js
import {OfflineSync,createSyncTransport} from '@corevia/js/sync';

const queue = new OfflineSync({store: yourAtomicDurableStore, binding: `${tenantId}:${actorId}`});
const transport = createSyncTransport({
  baseUrl: 'https://api.example.test/api/v1/sync',
  getToken: () => currentScopedAccessToken(),
});
await queue.enqueue('note-1', 0, {title: 'An offline draft'});
await queue.pull(transport);
const result = await queue.flush(transport);
```

`getToken` is read afresh for every request. Tokens stay in the transport/host credential store; the SDK never writes them into its queue. Do not put passwords/tokens into application record data. Use an explicit opaque tenant/actor binding and a separate local partition for each signed-in identity. A mismatched binding fails instead of exposing another partition's pending edits. The binding is not server authorization.

The JS runtime needs modern ESM, secure `crypto.getRandomValues`, `TextEncoder`, `TextDecoder`, `AbortController`, `Object.hasOwn` and a compatible fetch transport. Supply an explicitly secure `newOperationId` function/platform adapters if needed; there is no `Math.random` fallback. The fetch adapter refuses credentials/query/fragment-bearing base URLs, defaults to HTTPS and uses `credentials: 'omit'` with redirect rejection. Literal loopback HTTP requires an explicit development flag. It bounds credential lookup and network time, and caps response bytes. Modern streaming fetch enforces the byte cap while reading; a platform without a readable body uses its full-text response first, then enforces the cap. Such a platform must also enforce server/native response limits. React Native fetch/redirect/cancellation behavior and required polyfills must be verified on the selected native versions/devices.

`MemorySyncStore` is a teaching/test adapter and loses state when the process exits. Production `store.read()` returns `{version, payload}` and `compareExchange(version, payload)` must atomically write/increment only if the version still matches. Implement that operation using your chosen transactional local database/storage; an ordinary read followed by an unrelated write is not multi-owner CAS. The SDK retries a conflicting CAS at most eight times and never replays a network side effect inside a CAS callback. Choose your own at-rest data protection and device-lock/logout policy.

## Dart / Flutter

```dart
import 'package:corevia/sync.dart';
import 'package:corevia/sync_io.dart';

final queue = OfflineSync(store: yourAtomicDurableStore, binding: '$tenantId:$actorId');
final transport = IoSyncTransport(
  base: Uri.parse('https://api.example.test/api/v1/sync'),
  getToken: () async => currentScopedAccessToken(),
);
await queue.enqueue('note-1', 0, {'title': 'An offline draft'});
await queue.pull(transport);
final result = await queue.flush(transport);
```

`sync.dart` depends only on Dart core libraries. `sync_io.dart` is the optional native Dart/Flutter HTTP adapter; Flutter web needs its browser transport implementation of `SyncTransport`. It enforces HTTPS by default, current token lookup, no redirects, bounded response/time and active request/client cleanup. A late credential lookup after timeout does not start a request. `SyncStateStore` is the corresponding durable CAS port. `MemorySyncStore` remains in-memory only.

JS and Dart use the same format-1 queue snapshot and request/feed shapes. Native acceptance tests read each other's serialized snapshots, preserving pending edits and records. This is a development format contract; review versioned migrations before changing it or sharing state between application releases. Keep 64-bit identifiers as strings where necessary; mutation revisions are limited to portable exact integers.

## Conflict, disconnect and logout behavior

- An offline/unknown outcome keeps the exact operation and ID queued. Retry it after reconnect; do not generate a new ID for the same uncertain request.
- A record/input conflict pauses the queue at that operation. Pull current server state, show both versions, then call `resolveConflict(id, {action: 'drop'})` or rebase with an explicitly chosen current revision/new data. Rebase creates a new operation ID. There is no silent last-write-wins merge or automatic resurrection after deletion.
- `AUTH_REQUIRED`/403 stops synchronization and retains unsent edits. Reauthenticate the same identity before resuming. It does not erase offline work automatically.
- Changes and their resume cursor commit together in the same local CAS snapshot. A failed/disconnected page does not advance its cursor. Tombstones retain record revisions, and stale feed events cannot replace newer local acknowledged versions.
- An expired/restored-history cursor returns `reset_required`. `resetFeed()` clears only the downloaded cache/cursor, preserving pending edits, before replaying the authoritative feed. `reset()` explicitly discards the local partition; call it only for the application's deliberate logout/discard policy, and unmount/lock the old identity's UI/store before displaying a new identity.

Limits are 100 pending operations, 5000 cached identities, 16 KiB per record, 2 MiB total serialized state and 32 JSON levels. Non-finite/non-JSON values and unpaired UTF-16 surrogates are rejected. Flush handles at most 100 operations per call (default 20); pull handles at most 20 pages (default 5). One queue instance runs one network operation batch at a time; enqueue can still persist while a batch is waiting. Concurrent instances need a real atomic durable adapter. Store capacity/record rejection is explicit rather than silently evicting queued work.

## UI examples and acceptance

`examples/mobile/react-native/OfflineNotes.jsx` and `examples/mobile/flutter/offline_notes_page.dart` are host-app pages receiving an already configured queue and transport. They demonstrate save-offline, manual sync, retained edits after auth/network loss, explicit server/merge choices and reset-feed behavior. Use a new widget/component key and identity-specific store when accounts change. They are not generated native projects or a claim that Android/iOS builds, background execution, screen readers or a particular secure-storage plugin were accepted. The host chooses those integrations; no native dependency is added to the framework.

Run `php tests/v4/sync.php`, `node --test sdk/js/test/sync.test.js`, `dart run sdk/dart/test/sync.dart` and `python tests/v4/sync-http.py`. The last command requires PHP PDO SQLite, Node and a Dart SDK (`COREVIA_DART` or PATH); absence is an error, not a pass. It creates only an ignored `storage/sync-http-*` fixture and uses fake bearer credentials. Two separate JS processes simulate durable restart/lost acknowledgment, and native Dart then consumes the same actual PHP feed, including deletion. No real account, email, external API or live database is used. The file adapters in that fixture have a single sequential owner; they are not production multi-owner CAS implementations.

The SQL service suite includes actual MySQL/PostgreSQL receipt/API contracts. Eight-process SQLite WAL tests assert one shared retry receipt along with existing worker/approval/revision invariants. The expanded stress workload used a 30-second busy timeout and a 90-second process cap after exceeding the original 10-second wait; the retained passing run's workers took about 6–13 seconds on this machine. This is a correctness fixture, not a throughput/superiority benchmark. Device/native-runtime, sustained load and cloud/CI acceptance remain separate release gates.
