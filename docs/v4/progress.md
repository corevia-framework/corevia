# V4 implementation progress

Updated 2026-11-08 (Asia/Karachi). Work is local on `develop/v4`. No V4 push, registry publication, tag, release or deployment is included in this increment.

Work has started across the next five phases (C-G). The database-free welcome/workspace now has V4 responsive navigation, light/dark/system themes and an honest empty state. The three auth-kit and AI-view redesigns remain open; existing generated application files are preserved.

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
| C | Checksummed application migrations; joins/pagination; seeders/repositories; public testing toolkit; nested validation; API envelopes/OpenAPI, scoped bearer credentials and idempotency; private files/localization; V4 welcome/workspace; JSON/SQLite catalog reference | Run new contracts against actual MySQL/PostgreSQL services and required CI; record driver-bound acceptance. |
| D | Revocable server-validated tenant scopes; scoped SQL/documents/cache/files/retrieval; signed jobs with worker authority resolution | Passkeys, service accounts/org SSO, audit/retention/export/deletion, all three accessible multilingual auth redesigns, real-service tenant acceptance. |
| E | Transactional SQL outbox, fenced relay/retries/dead state; rotating webhook signatures and replay reservation contract; cooperative monotonic deadline | Inspector/traces/metrics, scheduler/overlap, full webhook operations/history, retry budgets/circuit breakers/cross-runtime propagation, hosting adapters and failure acceptance. |
| F | Atomic tenant usage reservations and budgeted provider wrapper; signed actor/tenant-bound resumable change feeds with optimistic revisions and tombstones | Ingestion/vector-provider/structured-output/evaluation/tool execution work, Dart/React Native offline UX and SDK workflows, AI frontend and real-provider acceptance. |
| G | Source-bound private acceptance runner, clean-package browser checks, expanded CI configuration and eight-process SQLite races | Complete D-F, real-service/provider/runtime/hosting/security/soak/load gates, remote CI and final publication. |

See [Phase C developer guide](phase-c-development.md), [D tenant boundaries](phase-d-tenants.md), [E operations](phase-e-operations.md), [F connected clients](phase-f-connected.md), and [G acceptance tooling](phase-g-acceptance.md). These are working increments, not five completed phases. No V4 push, provider login, mail delivery or production-site access is included.

Focused local contracts pass for the new C-F primitives; the initial full framework regression and existing A/B packaging checks pass. Eight separate SQLite processes verify bounded reservations, one optimistic writer and exclusive active outbox claims. The acceptance runner records the final source-bound results under an explicitly chosen ignored storage directory. New MySQL/PostgreSQL/MongoDB driver behavior and external provider/hosting checks remain unverified until their actual suites run.

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
