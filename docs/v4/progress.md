# V4 implementation progress

Updated 2026-11-08 (Asia/Karachi). Work is local on `develop/v4`. No V4 push, registry publication, tag, release or deployment is included in this increment.

Work has started across the next five phases (C-G). The database-free welcome/workspace has V4 responsive navigation, themes and an honest empty state. All three auth kits now have responsive V4 layouts, themes and English/Urdu/Arabic presentation with RTL support; native React account management passes its SQLite/MFA/provider/accessibility browser checks. The optional AI view remains open. Existing generated application files are preserved.

## Phase A: baseline/design complete

The [Phase A design record](phase-a-design.md) completes the compatibility matrix, persisted-storage/SDK inventory, package/file ownership, threat model, hosting capability design and benchmark methodology. Actual V3/current API and JSON backup fixtures pass; native PHP/JS/Python/Dart conformance checks pass. `tools/v4-inventory.py` records source inventories and a matched 180-sample in-process baseline. This closes design/baseline requirements, not exhaustive behavioral proof, comparative speed claims or Phase G hosting/security/load acceptance.

## Phase B: complete; publication scheduled for final V4 release

- `tools/v4-package.py` builds deterministic runtime/application ZIPs and SHA-256 inventories from explicitly selected Git-index files. Preview mode writes nothing. Existing output directories, output outside repository storage and selected indexed symlinks/conflicts are rejected.
- The application skeleton has its own Composer metadata, `App` autoloading and `tests/run.php` smoke check through `php corevia test`. The experimental `corevia-framework/runtime:dev-v4` dependency installs under `vendor/`; no framework `packages/` directory is copied into application ownership.
- Starter templates resolve from framework code. Bootstrap, Tailwind, React and the AI starter operate with detached dependencies while retaining V3 checkout support and overwrite refusal.
- Signed artifacts include detached runtime and executable Composer loader sources; dependency drift causes verification failure.
- Database-free routes and real loopback HTTP auth/CSRF/assets/private-file checks pass for all three UI stacks. AI is installed disabled; no external model or provider was contacted by these checks.
- The packaging integration test is added to the existing PHP CI matrix. CI has not been run for local V4 changes; this is a configured check, not a GitHub pass claim.

The [Phase B delivery guide](phase-b-delivery.md) documents working feature composition, extension compatibility metadata, staged V3/detached upgrades and the public_html/private upload layout. End-to-end fixtures cover application-edit/state preservation, unchanged source rollback copies, unrelated dependency pins, script rejection, stale-cache quarantine, fork refusal and shared-host HTTP isolation. The local Apache/FastCGI recipe also passes without touching installed services. Prepared Composer repository/ZIP consumer installation is tested. The maintainer explicitly chose to keep package publication for the final V4 release. Phase B is complete for the agreed local delivery scope; public upload/registry registration remains a Phase G release task, not a completed publication claim. Phases C-G are now in progress, with the bounded delivery scope and remaining acceptance below.

`starter:upgrade` remains a read-only auth-template comparison. `app:upgrade` implements separate staged replacement rather than in-place patching; no arbitrary database/schema migration or traffic switch is performed. `hosting:check` still separates measured prerequisites from unverified operator declarations and never grants production readiness. See [foundation commands](foundation-tools.md) and [delivery commands](phase-b-delivery.md).

## Phases C-G: current delivery status

| Phase | Implemented in this increment | Still required for phase completion |
| --- | --- | --- |
| C | Checksummed application migrations; joins/pagination; seeders/repositories; public testing toolkit; nested validation; API envelopes/OpenAPI, scoped bearer credentials and idempotency; private files/localization; V4 welcome/workspace; JSON/SQLite catalog reference | Actual MySQL/PostgreSQL contracts now pass; required remote CI and final regression remain. |
| D | Tenant-scoped adapters/jobs; scoped machine credentials; provisioned organization OIDC policy and MFA-bound assurance; HMAC audit chains/checkpoints/retention/holds; account exports and resumable erasure; optional real WebAuthn verifier with atomic ownership/CAS; multilingual/RTL auth screens | Audit/application integration review, real provider/device checks and manual accessibility/security review. |
| E | Transactional SQL outbox; webhook signature/replay contract; monotonic deadlines; bounded SQL cron with DST/dedup/overlap rules; job metadata/retry; shared retry quotas and generation-fenced circuit breakers | Inspector/traces/metrics, full webhook operations/history, cross-runtime deadline/cancellation propagation, hosting adapters and failure acceptance. |
| F | Atomic tenant usage reservations and budgeted provider wrapper; signed actor/tenant-bound resumable change feeds with optimistic revisions and tombstones | Ingestion/vector-provider/structured-output/evaluation/tool execution work, Dart/React Native offline UX and SDK workflows, AI frontend and real-provider acceptance. |
| G | Source-bound private acceptance runner, clean-package browser checks, expanded CI configuration and eight-process SQLite races | Complete D-F, real-service/provider/runtime/hosting/security/soak/load gates, remote CI and final publication. |

See [Phase C developer guide](phase-c-development.md), [D tenant boundaries](phase-d-tenants.md), [E operations](phase-e-operations.md), [F connected clients](phase-f-connected.md), and [G acceptance tooling](phase-g-acceptance.md). These are working increments, not five completed phases. No V4 push, provider login, mail delivery or production-site access is included.

Focused local contracts pass for the new C-F primitives; the initial full framework regression and existing A/B packaging checks pass. Eight separate SQLite processes verify bounded reservations, one optimistic writer and exclusive active outbox claims. The acceptance runner records the final source-bound results under an explicitly chosen ignored storage directory. The source-bound staging run at fingerprint `32822ca3ebb7e873f0cca0e5dd3b64bbb4eaf60804b54d260c4c4f2609dee30b` passed C migration/catalog, D audit/identity/lifecycle/SSO/passkey storage and scheduler/coordination contracts on MySQL/PostgreSQL; identity storage also passed MongoDB. The receipt is private at `storage/v4-service-upload-20261108/report.json`. Private service processes stopped successfully; external identity/device/hosting checks remain open. Later retry/outbox/sync service results are recorded separately.

## Current identity increment

The source-bound identity acceptance completed all 21 checks successfully with `source_unchanged: true` and `local_passed: true` (`storage/v4-phase-d-identity-acceptance-20261108/report.json`). This private receipt covers the staged identity increment before this documentation update. It intentionally records `release_ready: false` with the remaining gates.

See [identity, audit and account-data APIs](identity-and-data.md). JSON/SQLite lifecycle and service-account tests pass; organization policy fixtures preserve MFA/age/tenant requirements. Real WebAuthn verification passes with Chrome virtual authenticators on JSON/SQLite, including signed UV/RP/counter negatives and removal/replay checks. Passkey storage also passes a NoSQL CAS fixture. The optional verifier is locked at 5.3.12 for development acceptance; Composer reported no known advisories for that locked dependency set. This is not an independent security review.

All three refreshed starters pass the database-free auth/recovery/account workflow and automated WCAG-tagged scans for English/Urdu/Arabic, RTL, both themes, validation focus and keyboard navigation. SQLite browser flows also pass MFA/recovery/provider fixtures. Native React account management also passes its SQLite/MFA/provider/accessibility browser checks. Automated scans retain items needing manual review; they do not certify screen-reader, translation or physical-device acceptance. CI is configured, not yet run remotely. Work remains local.

## Try the prototype locally

Requires a Git checkout, Python 3.10+, PHP 8.2+ and Composer on the development/build machine. Add intended source changes to the Git index before building; an ordinary committed checkout already meets that requirement. The application runtime itself needs only PHP/JSON for the initial pages.

```sh
python tools/v4-package.py --output=storage/v4-preview --dry-run
python tools/v4-package.py --output=storage/v4-preview
cd storage/v4-preview/application
composer install --no-interaction --no-scripts --no-plugins
php corevia serve
```

Open http://127.0.0.1:8081. Choose a new output directory if one already exists; the builder never overwrites it. Keep the sibling `runtime/` directory during this local path-repository installation; Composer mirrors it into `vendor/`. The package is not yet on Packagist. Use `php corevia starter:auth --stack=bootstrap` (or `tailwind`/`react`) in a fresh generated application to add authentication.

## Verification commands

```sh
python tests/v4/packaging.py
php -n tests/v4/features.php
python tests/v4/phase-b.py
python tools/v4-inventory.py
php -n tests/v4/hosting.php
php -n tests/v4/upgrades.php
python tests/v4/compatibility.py
python tests/v4/persistence.py
python tools/architecture.py
php tests/v3/composition.php
php tests/v3/artifact-boot.php
php tests/v3/operations.php
php tests/v3/auth.php
php tests/features/web.php
node tests/v3/profiles.mjs
```

Acceptance artifacts remain ignored under `storage/`. Existing generated auth files in the working application were not incorporated into the skeleton or overwritten. Pre-existing artifact/web/profile test cleanups now remove only their own named temporary fixtures recursively, so nested auth storage no longer produces cleanup warnings.

The full regression run exposed transient Windows destination-file contention during concurrent identity updates. JSON auth now retries only the same prepared atomic rename for a bounded interval on Windows, retaining the lock and never deleting the committed file or rerunning the transaction callback. Nested auth-storage fixture cleanup was corrected in artifact, web and profile tests.

## Operations increment

See [scheduler](scheduler.md) and [resilience](resilience.md). Focused contracts cover DST repeats/gaps, dispatch retention after payload expiry, overlap skips, configuration drift, cancellation/cleanup, stale-worker acknowledgments, explicit dead-job retry, shared retry quotas and fenced recovery probes. Eight separate SQLite processes prove dispatch/claim/overlap uniqueness, one active probe and aggregate retry limits. `examples/scheduler/run.php` demonstrates migration, tick, work and metadata inspection without outbound calls. Cross-runtime propagation, full delivery operations, observability, AI/mobile work and final release acceptance remain unfinished.

The operations local acceptance passed all 13 checks with unchanged source (`storage/v4-operations-acceptance-20261108/report.json`). Staging then caught a one-millisecond, wall-clock-sensitive retry fixture; that test now uses a deterministic clock, and its focused local check passes. The corrected complete service suite passes at fingerprint `7a0897d44d02559b3ce1f5a490cd74c215a4651012bb530e76d38e621232c90c`, covering actual MySQL/PostgreSQL data/catalog, audit, machine credentials, lifecycle, SSO policy, passkey storage, scheduling, resilience, outbox and usage/synchronization; identity contracts also pass MongoDB. The private receipt is `storage/v4-operations-services-20261108/report.json`. Both owned service processes stopped, no system services changed, and no public ports opened. The failed fixture receipt is retained separately rather than overwritten. These are service-contract results, not real identity-provider/device/cloud or release acceptance.

## Telemetry, budgets and delivery increment

New local work adds bounded explicit PHP/Python spans, OTLP trace/delta-metric export, private readiness/metrics routes and a production-disabled developer inspector. Fiber/context-variable work budgets propagate into native HTTP, CVP/2 HTTP/process calls, PHP gateways and shared jobs. Real loopback tests cover slow streams, redirects, trace parent/child correlation and expiry/cleanup. Cancellation remains cooperative: there is no live authenticated remote cancellation RPC. Optional SQL outbox attempt history/operator redelivery, fixed signed webhook endpoints and shared replay reservations are implemented. See [operations and budgets](observability-and-budgets.md) and [webhook delivery](webhook-delivery.md).

Focused tests pass. The expanded history workload hit SQLite rollback-journal stress busy limits; the dedicated local-disk WAL stress configuration passes with unchanged eight-process ownership/quota checks. Single-connection rollback-journal fixtures remain. Final source-bound regression, real SQL contracts, external collector/hosting drills and soak/load acceptance for this increment are still pending. No application journal setting, live site or V4 remote branch is changed.

This increment passed all 15 source-bound local checks with unchanged source (`storage/v4-telemetry-delivery-acceptance-20261108/report.json`). The complete actual MySQL/PostgreSQL/MongoDB service suite also passed at fingerprint `cec3f4f7ed82eaeef9815307fec85efdf77d2c0761756b84289c07ef86f24815`; receipt: `storage/v4-telemetry-services-20261108/report.json`. Both owned services stopped successfully and system services/public ports remained unchanged. The result covers contracts and local loopback transports, not external collector/cloud/provider validation or live remote cancellation.
