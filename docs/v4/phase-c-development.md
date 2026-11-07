# Phase C development APIs

Local implementation, still using the V3 compatibility baseline. The public release remains gated by the V4 acceptance plan.

## Application data

Put ordered JSON migrations in application-owned `database/migrations/`. Each file declares an immutable ID and explicit `up`/`down` statement lists per SQL driver; see [the catalog migration](../../examples/v4/catalog/migrations/001_catalog.json). Run `php corevia migration:status`, `migration:up`, or `migration:down --steps=1`, optionally selecting `--connection=name` and `--directory=absolute-private-path`. No migration runs during HTTP boot.

The runner checks stored checksums and refuses missing/edited applied files, dirty executions and backwards IDs. SQLite/PostgreSQL batches are transactional. MySQL DDL can commit implicitly: execution is serialized with a connection lock and marked running before execution; failure requires manual schema/history reconciliation or matching backup restoration. A `down` is not a backup. Seeders are explicit operator calls: `Seeder::insert($db, 'table', $rows)` uses prepared inserts in a transaction. `RecordRepository` restricts writable columns. Query joins are read-only; pagination requires explicit stable ordering. Document stores keep their existing capabilities and do not pretend to support SQL migrations/joins.

## Tests and API development

`Corevia\Testing\TestClient` exercises the real router/middleware in-process, with cookie handling, authenticated session setup and response assertions. `Clock`, `Factory`, `FakeMail`, `FakeQueue`, and `FakeAi` are explicit development dependencies and refuse construction in production. They are not automatically substituted into application services. Queue claims retain ownership/lease checks. Use a disposable database per suite; no automatic truncation of application tables is provided.

`RequestValidator` adds bounded nested/wildcard validation without changing legacy flat validation. `ApiBoundary`, `ApiResponse` and `ApiProblem` supply opt-in versioned error/data envelopes. Add `Route::describe(operation, inputSchema, outputSchema, scopes, successStatus)` and generate OpenAPI 3.1 with `OpenApi::generate` or `php corevia openapi:export`. Documented scopes describe the contract; `ApiTokenMiddleware` enforces it. API tokens are random, returned once, digest-stored, explicitly scoped/tenant-bound, quota-limited and tied to current session/account authority. Bearer credentials cannot mint further credentials; manage issuance through an authenticated account session. Role/password/revocation changes invalidate their authority. Production bearer transport requires HTTPS. `ApiIdempotency` reserves before side effects; uncertain operations require reconciliation rather than unsafe automatic retry.

`LocalDisk` provides bounded private files and allowlisted real uploads with opaque names; traversal, symlinks and overwrites are rejected. Keep the directory private and treat its owner as trusted. `Translator` provides catalog fallback, named substitutions and RTL locale direction; views must still escape translated output.

## Frontend foundations

The database-free welcome, guide and `/workspace` shell use responsive navigation, visible focus, a skip link, reduced-motion support and a persisted system/light/dark theme. Workspace starts with an honest empty state. Auth links appear only when installed. Prebuilt external CSS/JS work without Node on the host. Existing generated auth files remain application-owned; their redesign/accessibility work belongs to Phase D.

## Evidence and remaining gate

Local PHP checks pass for SQLite migration/checksum/rollback/query behavior, JSON/SQLite reference API isolation/idempotency, API credential revocation/quota/purpose separation, nested validation, private files and test doubles. The V3 API-shape baseline and Phase B distribution regression pass. Chrome checks pass at 390, 768 and 1440 pixels, including theme persistence, keyboard skip navigation and overflow. Artifacts are private under `storage/`.

MySQL/PostgreSQL test jobs are configured but have not been executed for this increment. Full provider/CI validation remains an explicit acceptance gate; Phase C is implemented locally, not yet accepted against every advertised driver. No cloud, registry or release claim is made.
