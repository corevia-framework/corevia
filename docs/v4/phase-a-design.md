# Phase A: compatibility, boundaries and acceptance design

Design baseline recorded 2026-11-08 (Asia/Karachi). This document closes the baseline/design deliverables, not production release acceptance. V3 reference: `d7429ed14356de39aaf8e98fa0993fc5c8f38337`. Ownership is defined in [baseline.md](baseline.md); target constraints and competitive methodology are in [hosting-and-competitive-targets.md](hosting-and-competitive-targets.md).

## Compatibility matrix and persisted-format inventory

| Surface | Baseline contract | V4 rule / evidence |
| --- | --- | --- |
| PHP packages | Public/protected classes, interfaces, traits, enum values, callable parameters, inheritance and constants | API-shape gate compares actual V3 declarations; behavioral edits require regression fixtures, deliberate breaking edits require a migration decision. Literal defaults are not certified by reflection. |
| Application files | `app`, routes, resources, config, public files and custom dependencies belong to the application | Install into a new directory. Upgrade into a separate replacement. Never silently patch application PHP/config or delete obsolete files. |
| Flow JSON | `runs` and `keys` maps, saved flow definition/state/checkpoints and idempotency mappings; bounded at 16MiB | Actual V3 pause/resume and exact-backup rollback fixtures; no arbitrary changed flow-definition replay. |
| SQLite legacy flow storage | `corevia_flow_runs`, `corevia_flow_steps`, `corevia_idempotency` | Preserve existing tables and snapshot semantics; legacy fixture/regression suite remains required. |
| Portable SQL flow storage | `corevia_sql_runs` and `corevia_sql_keys`; MySQL/PostgreSQL/SQLite-specific locking and uniqueness | No schema change in A/B. Database contracts own platform acceptance; a driver change is not data migration. |
| Auth JSON | Format 1: users, sessions, limits, tokens and identity state; private atomic replacement | V3-created users/sessions/single-use tokens run under current code and restored backup under V3. Preserve password hashes, auth versions and signing/encryption material. |
| Auth SQL | Configurable-prefix mutex/users/sessions/limits/tokens/state tables, uniqueness and expiry indexes | Explicit idempotent schema setup; preserve names/types and transaction ownership. All three supported SQL drivers have existing service-gated contracts. |
| Mongo auth | Bounded snapshot with CAS/version ownership, application-selected collection/database | Preserve CAS contract; no conversion to SQL or multi-node availability claim. Real target service acceptance remains a release gate. |
| JSON/Mongo documents | Application-owned document IDs/payloads through the document-store contract | Preserve CRUD/identifier/corruption semantics; source inventory covers both adapters. No application document schema conversion or implicit cross-driver import. |
| Local jobs | Private job files with status/claim/expiry and flow identifiers | Preserve at-least-once/idempotency rules; do not promise exactly-once external effects. |
| Shared jobs | Prefix-specific mutex/jobs/leases tables, owner, lease/fence, attempt, readiness and expiry | Stale-owner/fencing contracts remain; physical-host failover and new adapters are later acceptance. |
| Local/shared mail | Format-1 bounded encrypted spool, or shared SQL coordinator/mail records | Preserve encryption keys, purpose and expiry; migration must not resend approved messages or expose credentials. |
| Build/config artifacts | Private format-1 HMAC artifact and format-1 environment/config cache | Recompile with matching runtime/config/source inputs; keep keys separate. Never copy stale source-bound artifacts as deployable proof. |
| CVP | CVP/1 and CVP/2 are independent of framework major versions | No CVP/4 implied. Legacy/default protocol choices and error/correlation contracts stay explicit. |
| JS/Python/Dart SDKs | Existing client/runtime/context/action APIs, streaming helpers and generated contract consumers | Inventory source hashes/exported symbols and run native SDK/conformance tests. Keep app/model/database imports outside SDK boundaries. |
| Contract manifests | Declared supported schema subset generates PHP/JS/TS/Python/Dart | Keep generated-file checks and valid/invalid cross-language fixtures. Authentication enforcement remains server-side. |

`python tools/v4-inventory.py` writes SDK/persistence/contract inventories and local V3/current measurements under ignored storage. Source hashes identify the reviewed implementation; they do not substitute for DDL review, runtime tests or application-owned migrations. Existing service and conformance suites remain the contract acceptance owners. This inventory explicitly records all current built-in storage families; future families require a new entry and migration disposition.

## Cross-cutting threat model

| Boundary / adversary | Failure to prevent | Required control and evidence |
| --- | --- | --- |
| Anonymous browser / forged origin | Session theft, CSRF, XSS, privilege assignment | Existing session rotation, generic auth errors, server-issued privileges, escaped output, CSRF/origin checks and browser negatives; preserve them during starter redesign. |
| Authenticated tenant / stale credentials | Cross-tenant reads, stale memberships, job/retrieval leakage | Deny-default server tenant identity, current session/version checks, adapter enforcement; comprehensive item 11 tests remain Phase D. |
| Package/extension supplier | Installer hooks, dependency confusion, capability spoofing | Explicit metadata, bounded runtime ranges, deterministic closure, no executable hooks, scripts/plugins disabled. Digests detect drift but do not establish publisher trust. Registry provenance/signatures remain a release/distribution concern. |
| Edited application / interrupted upgrade | Lost code, corrupted state, unmatched rollback | Stage a separate replacement; reject unknown legacy framework/loader forks; preserve application files/private state; require offline writers and external DB backup. Original remains the rollback copy. |
| Untrusted archive/path | Traversal, symlink escape, secret exposure | Strict relative paths, no symlinks in package operations, new output only, bounded inventories; public/private directory separation and HTTP denial fixtures. |
| Hosting operator/configuration | Public vendor/storage/config, wrong PHP, stale worker state | Capability diagnostics with measured/declaration provenance, safe bundle layout, explicit supervision/storage/secret policy. A label or declaration is never target certification. |
| Remote provider / model output | SSRF, unbounded streams/cost, prompt-driven authority | Fixed authenticated endpoints, TLS/deadlines/size limits and server-selected models; model output is untrusted data. Tool permissions/isolation/budgets belong to Phase F and must precede tool execution. |
| Worker retry / partial failure | Repeated external effects or lost claims | Idempotency and fencing, bounded retries/deadlines, scoped cleanup; downstream systems must enforce their own effect boundaries. |
| Maintainer/deployment trust | Compromised verifier or executable app code | Source/config integrity assumes trusted execution and key provisioning. No HMAC, inventory or extension manifest protects a server already controlled by an attacker. Independent review is a scoped later assurance step. |

## Accepted design decisions

1. Start with an aggregate runtime library and application skeleton; internal architecture boundaries remain enforced. Independent package releases need their own version/dependency tests.
2. Use explicit metadata with min-inclusive/before-exclusive framework/PHP bounds, dependencies, conflicts, provided capabilities and extension prerequisites. Resolve deterministically; hooks never execute during compatibility planning.
3. Generate into an empty application directory. All templates become application-owned once installed. Composer is build-machine tooling and runs with plugins/scripts disabled.
4. Upgrade by preparing a separate application directory, not patching a running tree. Detect source changes and legacy framework forks; preserve user data/code. External database backup/restore and final traffic switch remain operator actions.
5. Build public_html/private upload artifacts off-server, excluding live state. Preserve opaque application configuration, never execute it while inspecting/bundling. Inspect for inline secrets before sharing artifacts.
6. Keep compatible improvements additive; retain framework/SDK 3.0 metadata during development, with `dev-v4` distribution metadata until release versioning.

## Benchmark methodology and baseline

The baseline harness uses the same PHP executable for the actual V3 source and current source, performs 20 warmups and 180 measured boot/dispatch/shutdown samples, and records p50/p95/p99 plus process peak memory. It performs no external I/O, does not include PHP process startup and is **not** HTTP throughput or a Laravel/CodeIgniter comparison. Raw output lives in `storage/v4-baseline-benchmark.json`.

Phase G must add end-to-end HTTP/auth/database, durable jobs, crash/soak and AI-stream workloads with fixed versions/hardware/security settings, errors and raw results. No speed target or comparative advantage is inferred from the in-process sample. This closes methodology/baseline design; release performance and physical-host/provider acceptance remain Phase G.
