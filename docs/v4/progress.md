# V4 implementation progress

Updated 2026-11-09 (Asia/Karachi). Work is local on `develop/v4`. No V4 push, registry publication, tag, release or deployment is included in this increment.

Work has started across the next five phases (C-G). The database-free welcome/workspace has V4 responsive navigation, themes and an honest empty state. All three auth kits now have responsive V4 layouts, themes and English/Urdu/Arabic presentation with RTL support; native React account management passes its SQLite/MFA/provider/accessibility browser checks. The common AI view now passes multilingual/theme/streaming/cancellation checks in all three starter installations. Existing generated application files are preserved.

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

| Phase | Implemented so far | Still required for phase completion |
| --- | --- | --- |
| C | Checksummed application migrations; joins/pagination; seeders/repositories; public testing toolkit; nested validation; API envelopes/OpenAPI, scoped bearer credentials and idempotency; private files/localization; V4 welcome/workspace; JSON/SQLite catalog reference | Actual MySQL/PostgreSQL contracts now pass; required remote CI and final regression remain. |
| D | Tenant-scoped adapters/jobs; scoped machine credentials; provisioned organization OIDC policy and MFA-bound assurance; HMAC audit chains/checkpoints/retention/holds; account exports and resumable erasure; optional real WebAuthn verifier with atomic ownership/CAS; multilingual/RTL auth screens | Audit/application integration review, real provider/device checks and manual accessibility/security review. |
| E | SQL scheduling/retry/circuit quotas; private inspector/health/metrics; optional OTLP export; PHP/Python work budgets/traces; durable webhook history, signed delivery/replay and controlled redelivery | Authenticated collector/TLS/partition drills, full real hosting acceptance and sustained worker soak/load; optional single-process PHP-to-Python live cancellation passes local and Linux checks. |
| F | Tenant budgets; immutable knowledge revisions and active filtering; real Qdrant index contract; structured output/evaluations; one-use permissioned tools/human review; refreshed AI UI; SQL retry receipts; JS/Dart offline queues/transports and native UI page examples | Application-specific model/provider validation is user-run by maintainer decision; OS-isolated-tool validation, native device/storage/background acceptance, sustained vector/mobile workload and security review remain. |
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

## AI application increment

[AI platform APIs](ai-platform.md) now add contract-validated buffered JSON output, immutable document staging/activation/tombstones with active-revision retrieval filters, explicit collection, bounded retrieval evaluations, optional Qdrant dense search, one-use capability/schema-bound tool requests and human-session review. Fixed process/HTTP tool transports preserve budgets; process environments can be explicitly allowlisted. Trusted callbacks/processes are not an OS sandbox. The optional AI page is refreshed across the common server-rendered UI for Bootstrap/Tailwind/React, with localized RTL/themes, safe streaming and cancellation/partial/error states.

Local contracts and eight-process WAL activation/approval races pass. An official SHA-256-verified Qdrant v1.20.0 private staging process passed initial generic index provisioning/query/tenant/update/delete contracts, then stopped. Revised ingestion/Qdrant integration, full SQL and final source/browser acceptance for this increment are still being run. No real paid model, OAuth provider, live site, public deployment or email is exercised. Mobile/offline SDK workflows and final provider/OS/cloud/security/soak acceptance remain open.

The AI increment passed all 18 source-bound local checks with unchanged source (`storage/v4-ai-platform-acceptance-20261108/report.json`), including all three AI starter browser/accessibility modes. The complete actual SQL/MongoDB suite and Qdrant v1.20.0 revision-lifecycle contract both passed with source unchanged at fingerprint `f254671bce602327311e5d99b252efbbd4cc9fd37c83d8de4bdaadaae7d55ae4`; private receipts are `storage/v4-ai-services-20261108/database-report.json` and `vector-report.json`. All owned service processes stopped. A subsequent small hardening change sanitizes anonymous PHP exception identities (which can include source paths), and the decorative AI icon uses an encoding-independent HTML entity; focused checks cover the hardening. No remote publication is included.

## Offline/mobile increment

[Offline synchronization](offline-sync.md) adds atomic SQL retry receipts and opt-in scope-checked API routes, JS/TypeScript/Dart bounded queues, native HTTP transports and host-app React Native/Flutter page examples. Native loopback acceptance verifies two-process JS lost-ack restart, Dart/JS feed and serialized snapshot interoperation, deletion, explicit conflicts and current-token/tenant separation. SDK unit/analysis checks pass. Eight-process local SQLite WAL races now include one shared sync receipt; the enlarged workload exceeded the previous 10-second lock wait, then passed with a documented 30-second busy timeout/90-second process cap and measured worker durations of 6-13 seconds. Assertions/workload were retained.

Full source-bound distribution/mobile regression and actual MySQL/PostgreSQL tests for this increment are pending. Native device/background/storage-plugin, real hosting/cloud/model/collector, external security and sustained-load acceptance remain open; examples and fixtures do not certify them. V4 remains local.

The offline increment passed all 18 source-bound local checks with unchanged source (`storage/v4-offline-sync-acceptance-20261108/report.json`). The complete real MySQL/PostgreSQL/MongoDB service suite passed with unchanged source at fingerprint `426070192c59418b1f247cf597ac04e421ea68aea3aa69ad2fb10b39fc11ce9e`; private receipt: `storage/v4-offline-services-20261109/report.json`. All owned service processes stopped. A subsequent focused hardening change makes both SDKs report corrupt offline JSON with a fixed code rather than a parser message that could include private data; unit/native HTTP checks pass after that change. No push/publication is included.

## Hosting and bounded-worker increment

[Hosting recipes](hosting-presets.md) now emit private hash-inventoried shared/VPS/AWS/Azure files with no installation or account access. Detached packages include these resources. Queue CLI batches have optional cooperative total budgets and explicit stop reasons. Local contracts cover private path/overwrite controls and 1,000 real SQL job scopes, including 40 injected failures and timeout cleanup. Actual isolated Linux nginx/FPM routing checks pass for both VPS/Azure recipes; the VPS pool, systemd syntax and Azure shell syntax also pass without installing services or executing startup. All owned processes stopped. These checks do not certify actual cloud/shared platforms or sustained workloads. Full source-bound distribution regression and Apache recipe checks are being completed; V4 remains local.

The hosting increment passed all 15 source-bound local checks with unchanged source (`storage/v4-hosting-acceptance-20261109/report.json`), including both copied Apache recipe bundles and the detached workspace browser. The separate Linux routing/FPM/systemd/shell receipt is `storage/v4-hosting-services-20261109/report.json`, with template hashes and all owned processes stopped. Its synthetic public router checks recipe isolation; it is not a complete deployed framework/cloud acceptance run.

## Actual Collector acceptance

Official SHA-256-verified OpenTelemetry Collector v0.165.0 accepted three PHP spans and both delta metrics in a private loopback process, with trace correlation and no exception-message sentinel. The first attempt exposed an empty `partialSuccess` compatibility defect; the corrected exporter accepts only empty/default partial messages and still rejects warnings, counts and malformed responses. Shutdown/outage tests confirm a bounded visible failure and no hidden resend queue. Source-bound local regression passed all 12 checks (`storage/v4-collector-acceptance-20261109/report.json`). The actual Collector receipt (`storage/v4-collector-services-20261109/report.json`) binds source `a521eca7199b0090d3da455a114171161a6bd323bf7010213d6eee351ccc2def`, binary and test hashes; its owned process stopped. TLS/authenticated backends, persistence, partial partitions and sustained outage/load remain separate acceptance work. Failed fixture receipts are retained. No system service or live site changed.

## Live cancellation increment

[Optional live cancellation](live-cancellation.md) now adds authenticated, capability-bound PHP control requests to a bounded single-process Python registry. Current control and action-target authority are checked; old capabilities cannot stop a newer reused ID; release is identity-fenced and shutdown signals cooperative work. PHP gateways explicitly reject unsupported live cancellation. Local PHP/Python HTTP and unit contracts pass for control denial, registration capacity, revoked target policy, concurrent signals, cleanup and shutdown. Full source-bound regression is being run. No cross-replica mailbox, Python-to-PHP receiver, pre-registration delivery guarantee or forced interruption of blocked native work is claimed.

The cancellation increment passed all 13 source-bound local checks with unchanged source (`storage/v4-cancellation-acceptance-20261109/report.json`), including actual concurrent PHP/Python controls, the complete regression and detached distribution/upgrade fixtures. Python reports 22 unit tests passing, and V3 public API compatibility plus architecture boundaries pass. The control transport belongs to HTTP rather than the protocol package to preserve the enforced dependency boundary. No publication is included.

## Endurance and exception privacy increment

The first 60-second local lifecycle run processed 56,000 jobs with 2,240 deliberate failures and 56,000 cleanups, with no PHP allocated-memory growth after warmup. An actual killed-worker fixture waited for the real lease before reclaiming and preserved a single simulated idempotent effect while rejecting a stale acknowledgment. This short in-memory workload is not a production throughput comparison. The optional runner now also records Linux RSS growth and supports bounded longer runs. Exception identities are being sanitized consistently in action/cleanup/API/account/mail/job/realtime error paths; ordinary named classes remain intact. Focused privacy tests pass without sending any email. Full local and private Linux/VPS source-bound checks are being completed. [Release validation](release-validation.md) lists the actual environments still needed; no cloud/device provider is marked passed by a VPS test.

This increment passed all 13 source-bound local checks (`storage/v4-endurance-acceptance-20261109/report.json`). On the authorized private Linux server, eight fixture groups and 21 additional PHP 8.2.35/Python/runtime checks pass with source unchanged. The 180-second run processed 196,975 jobs, 7,879 injected failures and matching cleanups; both PHP allocated-memory and Linux RSS growth after warmup were zero for this bounded fixture. Real process-kill/lease-expiry recovery passed with a single simulated idempotent effect. Separate TLS nginx/FPM production-mode acceptance passed signed boot/deployment checks, two independent app pools, shared PostgreSQL session/revocation and a single claimed job across workers. This demonstrates same-host staging, not physical-host failover or cloud acceptance. All owned PostgreSQL/nginx/FPM/worker processes stopped; system services/public ports remained unchanged. Receipts are under `storage/v4-endurance-services-20261109/` at source `237ebe88553127b6f6ccbfaaa91c4aebdfab374e48ed883d524d7e6c1d433e81`. This later annotation changes documentation only. Real cloud/shared providers, identity/devices/models, native mobile integration, independent/manual reviews, longer intended-workload acceptance, Docker, remote CI and final publication remain open.

A final combined local frontend/passkey/mobile/regression receipt is planned at `storage/v4-combined-local-20261109/report.json`; inspect its actual status rather than treating this command/path as a pass claim.

The final combined local run passed all 30 checks with unchanged source (`storage/v4-combined-local-20261109/report.json`): PHP regression/races/architecture/V3 compatibility, detached packages/upgrades, workspace, all auth/browser/accessibility modes, all AI stacks, real virtual-authenticator verification plus locked dependency audit, JS/Python SDKs, Dart analysis and native sync HTTP. A subsequent test-only refinement removes one-millisecond sleeps from the worker fixture and exercises parent cancellation deterministically; focused checks pass on local PHP 8.3 and server PHP 8.2.35. Runtime/package source bytes remain unchanged from that 30-check receipt. This annotation/fixture update does not claim a new full-source receipt. No V4 push, publication or live-site change is included.

## Installed models and wireless Android setup

Per the maintainer, application-specific model/provider validation is delegated to users; the framework now supplies optional real local-model smoke tests. On installed Ollama 0.7.1, all four existing weights (Phi-3, Llama 3, Mistral and DeepSeek Coder) streamed through Corevia with an explicit eight-token short-prompt workload. The earlier 24-token/different-prompt CPU run timed out for three models; its failed receipt is retained at `storage/v4-local-models-20261109/report.json`. Successful bounded receipts are under `storage/v4-local-models-small-20261109/`, `storage/v4-local-models-other-20261109/` and `storage/v4-local-models-final-20261109/`, each binding its actual source/model digest. Client-stream cancellation and scope cleanup passed; DeepSeek/Phi-3 structured replies were rejected by the schema guard, so no model-quality or guaranteed JSON-generation pass is claimed. No model weights were downloaded or installed models changed.

Official-checksum-verified portable Android Platform Tools 37.0.1 and a one-time local pairing helper are prepared under ignored `storage/android-device-lab/`. Wireless pairing and connection to the authorized Android 11 arm64 phone succeeded. The helper uses a separate ADB server port and does not save the pairing code. See [the mobile guide](local-models-and-mobile.md) and [native device lab](mobile-device-lab.md). Pairing alone is not application acceptance; the partial native results are recorded below. V4 remains local and live sites are unchanged.

## Android lab increment, 2026-11-10

The optional generator now creates a private Flutter Android host around the current Dart SDK, a dedicated receipt-verified debug signing key, synthetic file-backed server and offline note UI. Reopening an offline draft restores its text in the shared Flutter page. Local widget and real-file tests cover saved drafts, atomic single-isolate CAS, lost acknowledgments, conflict rebase and tombstones. The standalone app simulates its server on the phone; real applications still need their configured backend for synchronization.

Flutter 3.47.9/Dart 3.13.7, Java 21, platform 36, build-tools 36.0.0 and NDK 28.2.13676358 produced a 78,573,527-byte standalone debug APK in explicit OpenGL mode. All six build-machine checks pass with source unchanged at `storage/android-device-lab/native-lab-v12/acceptance/report.json`, APK SHA-256 `b0020287b275dd36398898a5b7c482c9457344229dfb2597fb58ca5430a2a239`. That no-device receipt correctly keeps `native_device_tested: false` and `release_ready: false`.

On the physical phone, the default-renderer attempt stalled awaiting its first frame, including after foregrounding. Explicit OpenGL mode passed the real filesystem contracts and seeded a private queued probe (`native-lab-v10/acceptance/device-contract.log`), but native HTTPS mutation failed because the fixture proxy rejected Dart's chunked body. The proxy now supports bounded chunked forwarding; five independent local verified-TLS framing checks pass at `storage/android-device-lab/proxy-framing-check/report.json`. At that point the corrected native HTTPS flow and standalone process-restart verification were pending. The wireless connection then dropped, and the maintainer deferred phone testing until charging was complete. Failed receipts were retained. The resumed results are recorded below; no default-renderer pass, React Native/iOS acceptance, production storage/encryption, mobile accessibility or background-operation certification is claimed. No additional email, live-site change or V4 push is included.

## Android phone acceptance, 2026-11-15

The resumed physical Android 11 arm64 lab in explicit OpenGL mode passed real-file CAS/reopen/lost-ack/conflict/tombstone contracts and actual PHP/SQLite HTTPS synchronization. The transport checks reject an untrusted CA and wrong hostname, accept the explicit temporary test CA, separate tenant feeds, retain queued edits after a rejected mock bearer token, refresh credentials and synchronize deletion. These are synthetic fixture identities, not real identity-provider or token-expiry acceptance. IO/channel contracts now use ordinary on-device tests with explicit native success/failure reporting; a widget's initial rendered frame cannot block those contracts. Earlier frame waits also occurred in OpenGL mode, so no graphics-driver defect is diagnosed.

The runner uses Flutter's `--no-uninstall` option, verifies the seeded edit before updating the APK, then stops its private backend/removes its reverse mapping before force-stopping and reopening the standalone app twice. Fresh launch markers and the same queued probe passed both real process restarts. Twelve logged build/device steps passed in `storage/android-device-lab/native-lab-20261115-c/acceptance/report.json`; that full attempt remains recorded as failed because the OEM's UI dump command returned a notice without XML on stdout. A focused completion used the corrected public UI helper, which reads a unique Corevia-only temporary XML file, deletes it immediately and inspects only the fixture's own title/control labels.

The passing combined receipt is `storage/android-device-lab/native-completion-20261115/report.json`. It binds the original failed receipt, verifies unchanged application/backend source hashes and confirms that the installed standalone APK exactly matches SHA-256 `0bbfd5feb4031b10b259528d12635020ffcfe4846f656a276e5d6167f7c22931`. It records split evidence explicitly and keeps `release_ready: false`; the original failure was not rewritten as a pass. The focused UI check passed on that same APK. Five TLS/framing regressions also pass against the final driver at `storage/android-device-lab/proxy-framing-20261115-complete/report.json`, and architecture checks/Python compilation pass. No runtime package source changed in this increment.

Corevia Lab remains installed for an independent on-phone simulated-server demonstration. All owned test web servers and tunnels stopped. Default rendering, additional devices/OS versions, React Native/iOS, production encrypted/multi-owner storage, manual accessibility and background policies still need their own acceptance. V4 remains local; no release or production-site operation is included.
