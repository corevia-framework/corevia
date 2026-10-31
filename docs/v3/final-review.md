# Final V3 scope, security and upgrade review

The maintainer confirmed points 1–8 below as the review scope because their earlier numbering was not stored in the repository. Points 9–14 retain the original agreed architecture requirements. This review covers framework implementation and documented limits; it does not authorize touching existing production sites, publish a release, or claim independent security certification.

| Point | Reviewed implementation | Verification / boundary |
| --- | --- | --- |
| 1. Three auth starters | Additive Bootstrap, Tailwind and React kits; compiled assets, sessions, CSRF and protected routes | `auth-browser.mjs` runs all three stacks with minimal JSON, encrypted JSON and SQLite. Installers reject overwrites. |
| 2. SQL/NoSQL storage | Lazy PDO MySQL/PostgreSQL/SQLite, JSON documents, MongoDB and atomic auth snapshots; explicit schema setup | `databases.php`, `auth-storage.php`, `adapters.php`; real MySQL/PostgreSQL/MongoDB staging contracts. No automatic account migration or general ORM is claimed. |
| 3. Stronger identity | TOTP/recovery codes, bounded remember-me and provider-neutral OAuth/PKCE/nonce with GitHub/OIDC ports | Identity/provider fixtures and browser flows; real provider login explicitly deferred. Provider flags default off. |
| 4. Account/recovery/mail | Profile/email/password changes, deletion, session revocation, bound single-use recovery and encrypted mail queues | Recovery/identity/mail tests; one approved real SMTP message accepted and confirmed in Inbox. Production email defaults off until configured. |
| 5. Role/tenant authority | Exact role abilities, scoped memberships, resource ownership and current session/version checks | Deny-default identity/gate contracts and browser admin/account checks. Role CLI is an operator API. |
| 6. Jobs/mail/realtime | Shared SQL claims/leases/fencing, bounded workers, encrypted shared mail; existing authenticated websocket lifecycle | Real SQL coordination and two independent FPM/app workers; websocket integration. At-least-once execution needs downstream idempotence/fencing. Physical-host failover is not certified. |
| 7. AI and SDKs | Bounded streaming chat, embeddings, tenant retrieval, Python model adapter/host, JS/React/Dart consumers | AI unit/browser/SDK tests and actual cached phi3 inference/cancellation. Exact-search indexes and snapshots are bounded; GPU scheduling, arbitrary tools and production load are operator/application scope. |
| 8. Deployment/developer/upgrade tools | CLI generators, signed artifacts, deployment inspector, install/upgrade/release checks and templates | Real TLS nginx/FPM signed boot, private-file denial, shared revocation and clean Composer install. Docker recipe validation explicitly deferred. |
| 9. Deterministic module composition | Explicit canonical dependency/capability graph, stable independent ordering and failure-before-registration checks | `composition.php`, shuffled plans and graph fixtures. V2 `modules()` registration-order semantics remain available. |
| 10. Capability presets/runtime profiles | `web`, `api`, `worker`, `realtime`, `ai`, `full`, lazy catalog/closure and guards | Six database-free profile tests plus live API/worker/realtime checks, including ctype-disabled minimal PHP. |
| 11. Explicit execution lifecycle | Per-unit scoped DI and reverse cleanup for HTTP/CLI/jobs/websocket/Python/model inference | Scope/Fiber/thread/partial-startup/error/cancellation fixtures. Custom adapters must own their scope; blocked native inference requires process supervision. |
| 12. Architecture boundaries | Machine-enforced PHP package, Python adapter and frontend SDK dependency rules | Repository parser gate plus six negative-fixture groups; audited operator loaders stay outside SDK boundaries. |
| 13. Reproducible build/config artifacts | Canonical config/graph, source/lock fingerprints, separate private HMAC key and verified boot | Byte-identical artifact, drift/key/environment tests and real signed FPM boot. Only declared source roots and registrations are covered. |
| 14. Cross-runtime contract manifest | One schema/metadata source generates PHP/JS/TS/Python/Dart bindings | Shared conformance fixtures, generated output checks and TS/Dart analysis. Supported schema subset is explicit; auth enforcement remains server-side. |

## Security review disposition

Reviewed credential/version transactions, recovery token purpose/account binding and single use, MFA replay/purpose-bound encryption, OAuth state/PKCE/nonce/trusted-key verification, role/tenant authority, bounded authenticated transport, worker ownership/cleanup, private artifacts and frontend output/cookie protections. Findings fixed during acceptance: malformed auth origins, misleading SQLite multi-host approval, exposed signing/auth material and unsafe Unix-permission checks, valid buffered HTTP streaming rejection, and an undeclared minimal-Linux ctype dependency. Regression and cross-platform tests cover these fixes.

No unresolved release-blocking implementation finding remains in this agreed scope. Live OAuth/image validation are documented deferrals, not security-test passes. This implementation review is not independent penetration testing; each deployment still needs its own configuration, secrets, privilege, patching and operational review.

## Upgrade and rollback disposition

`python tools/v3-upgrade-verify.py` extracts the actual local `v2.0.0` tag into ignored storage, runs an unchanged legacy module/action/flow to create a paused persisted workflow, resumes the same identity/data under the current engine, restores the exact pre-upgrade JSON backup, then resumes under V2 again. This test passed. Existing V1/V2 fixtures, database contracts, clean install and application-owned-file overwrite guards also remain mandatory.

Keep old code/config/artifacts, data backups and encryption keys together. Selecting another database does not import accounts; schema/data rollback and external side effects require their own policy. A rollback restores a matching pre-upgrade backup rather than promising that arbitrary V3 writes can be downgraded. Existing production sites are explicitly outside this task and were not upgraded.

The three review flags in `acceptance.json` record this completed framework-scope review. Final source-matching regression/service receipts and GitHub CI remain required by `release:check`; review completion does not synthesize CI success or publish V3. See [upgrade notes](upgrade.md) and [optional acceptance](optional-validation.md).
