# V3 foundations: local phase

Release-preparation metadata is now 3.0.0; historical references to an unchanged 2.0.0 version below describe earlier milestones. The stable V3 tag remains pending mandatory GitHub CI.

Current release review: the maintainer-confirmed 14-point scope, implementation-security and framework upgrade reviews are complete in [final review](final-review.md). Real databases, TLS nginx/FPM and SMTP have staging evidence. Live OAuth and Docker validation are explicitly deferred with [self-test instructions](optional-validation.md); mandatory GitHub CI remains pending. The milestones below retain their original historical context.

Later production hardening and real database/nginx/FPM acceptance are recorded in [production readiness](production-readiness.md). Earlier fixture-only statements below describe their original milestones, rather than the current acceptance state.

This phase finishes the foundations previously marked partial. V3 is not released. Work stays on the local `develop/v3` branch until the complete V3 plan is ready; no push, PR, merge, tag or release is part of this phase.

| Plan point | Delivered in this phase | Scope |
| --- | --- | --- |
| 9. Deterministic module composition | Explicit canonical module/dependency order, topology/capability checks before registration, inspectable registration graph | Opt in with `compose()`. Existing V2 `modules()` keeps its behavior. |
| 11. Explicit execution lifecycle | Per-unit scopes, scoped DI, reverse cleanup, nested/Fiber isolation, singleton-capture guard; PHP HTTP/flow/CLI/job/websocket integration; Python per-dispatch and startup/shutdown hooks | Buffered HTTP responses and the existing synchronous/threaded workers. Custom adapters must enter their own scope. |
| 13. Reproducible config/build artifacts | Canonical effective config and graph, source/lock hashes, private HMAC artifact, inspect/verify commands, starter boot verification | Declared source inputs and declared registrations. This is not an executable cache or proof of arbitrary factory behavior. |
| 14. Cross-runtime contract manifest | One application manifest, portable schema validation, auth/error/event metadata, deterministic generated PHP/JS/TS/Python/Dart bindings, shared conformance fixtures | Explicit supported schema subset; auth enforcement remains the application's responsibility. |

## Subsequent local phases

| Plan point | Delivered | Scope |
| --- | --- | --- |
| 10. Runtime profiles | Six presets, explicit lazy catalog, deterministic dependency closure, include/exclude overrides, feature guards, profile-aware API/worker/realtime bootstraps and signed artifact restoration | Adapter policy and module selection; no automatic external service/model installation. See [profiles](profiles.md). |
| 12. Architecture boundaries | PHP package dependency policy, Python SDK import rules, frontend import constraints, source parser helpers, CI gates and negative fixtures | Static owned-source dependencies, with explicit audited loader exceptions. See [architecture](architecture.md). |

Foundation points 9–14 now have local implementations and regression coverage. Feature work and acceptance are tracked below. The foundations alone do not provide model hosting, GPU scheduling, RAG or AI security policies.

## Auth starter phase

The next feature phase adds `starter:auth` for Bootstrap, Tailwind and React, offline-ready assets, explicit auth-store/service contracts, private single-node JSON storage, registration/sign-in/sign-out, session-bound CSRF, rotation/revocation, attempt limits and a protected dashboard. The generator is additive and refuses overwrites. Existing apps stay unchanged until a kit is explicitly installed. See [auth starters](auth-starters.md).

The base auth/UI milestone is followed by storage/recovery, identity/delivery/authorization and the six follow-on workstreams below. Their implementation and acceptance are separate from this base milestone. The framework version remains 2.0.0 and V3 stays unpublished.

## Auth storage and recovery phase

Added SQL auth adapters for SQLite/MySQL/PostgreSQL with explicit migrations, versioned sessions, atomic single-use account tokens and stale-login protection. Added password recovery, matching-account email verification, optional verified-dashboard gating, private development delivery previews, a production delivery interface and UI in every starter. See [storage/recovery](auth-storage-recovery.md). No real email is sent by preview delivery.

JSON/SQLite contracts, concurrent token consumption and browser flows passed locally. MySQL/PostgreSQL service contracts are configured in CI but remain unrun for this local phase. Existing apps and generated files are not overwritten; new configuration/wiring must be merged explicitly. Production delivery can use the queued SMTP implementation added below or a configured application adapter.

Local validation passed for extension-free auth/security tests (including concurrent email uniqueness), real browser flows in all three UI stacks with external requests blocked, reproducible bundled assets, signed auth-policy drift, installer overwrite protection, PHP regression/lint, architecture gates and existing live HTTP/WebSocket/Python integration. The browser/rebuild CI job is configured locally; GitHub CI has not been run.

The profile phase adds six database-free boot checks, catalog/override/guard/drift tests and live API/worker/realtime tests. Architecture checks include six test groups with deliberately forbidden PHP, Python, JS and Dart dependencies, plus harmless-comment/string cases. Existing PHP regressions, starter-page/form tests and live HTTP/WebSocket/Python integration passed locally. Updated CI is stored locally and has not been run on GitHub.

## Four subsequent local auth phases

| Workstream | Implemented | Scope |
| --- | --- | --- |
| Stronger authentication | TOTP MFA, single-use recovery codes, bounded remember-me, provider-neutral authorization-code/PKCE integration and MFA challenge | Core provider port, extended by the GitHub/OIDC clients in the follow-on workstreams. |
| Account management | Profile/email/password updates, account deletion, session listing and owner-checked revocation | Current password/MFA checks, transactional version changes and session/token invalidation. Application resource deletion needs a domain policy. |
| Production email | Private encrypted durable spool, leased workers with retries/expiry, TLS-only SMTP, CLI status/work commands and execution scopes | Single-host file spool, at-least-once delivery; real external mail-provider delivery remains untested locally. |
| Authorization | Exact role/ability maps, scoped tenant memberships, server-loaded resource ownership, deny-default middleware and operator role CLI | Atomic session/version/assignment snapshots. Global roles do not bypass tenant membership. |

See [identity, mail and authorization](identity-mail-authorization.md) for setup, contracts and operational limits. The basic JSON kit still works without OpenSSL; encrypted features require it and existing MFA accounts fail closed without a verifier. SQL identity state uses an additional migration table. The follow-on workstreams add NoSQL/provider/shared/AI/deployment implementations; passkeys and organization/billing features remain optional future work. These milestones do not declare all 14 V3 points complete.

Local acceptance passed all nine Chrome combinations (three UI kits with extension-free JSON, encrypted JSON and SQLite), account/role CLI flows, concurrent JSON/SQLite recovery-code consumption and unique provider binding, RFC TOTP vectors, encryption tamper checks, leased mail retries/expiry/cleanup, and loopback SMTPS/STARTTLS certificate/hostname/downgrade checks. PHP regressions/lint, asset reproduction, architecture gates and existing live HTTP/profile/WebSocket/Python integration passed. External email, real OAuth providers and live MySQL/PostgreSQL identity services remain untested locally; CI configuration is local and has not run on GitHub.

## Six follow-on local workstreams

Added GitHub and nonce-bound signed OIDC clients, MongoDB/CAS authentication, shared SQL job leases/fencing and encrypted mail, streaming chat/embeddings/tenant retrieval, a bounded Python model host, authenticated AI starter, JS/Dart streaming helpers, deployment templates/checks, upgrade notes and source-bound local acceptance/release checks. See [remaining workstreams](remaining-phases.md) for runnable setup and explicit scope.

The user selected local fixtures. Real OAuth/SMTP and live MySQL/PostgreSQL/MongoDB acceptance remain unverified, as do GitHub CI and target deployment/container execution. Docker/services are unavailable in this environment. `release:check` is deliberately false until matching local evidence, plan/security/upgrade review and external acceptance exist. No push, merge, tag, release or version bump is performed by these phases.

## Validation

`php corevia test` includes the new composition, scope, adapter lifecycle, artifact boot and PHP contract checks. Generated bindings share 21 application fixtures across PHP, JS, Python and Dart. A TypeScript compilation checks valid calls and expected type errors. CI configuration includes these checks; updating the workflow locally does not run GitHub CI.

The foundation tests include shuffled module registration, byte-identical signed artifacts, source/lock drift, wrong keys/environment, malformed dependency plans, scoped resource disposal on success/failure, concurrent PHP Fibers and Python threads, HTTP/controller injection, queued jobs, websocket message callbacks, partial Python startup failure and guarded regeneration.

Full V2 regression and live transport suites remain required. Live MySQL/PostgreSQL/MongoDB service coverage belongs to the existing CI job and requires those services; database-free and SQLite checks can run locally. This phase does not certify all 14 V3 points or expand the V2 production-readiness claim to unreleased V3.

Local checks passed for the PHP suite/lint, database-free learning application, SQLite adapter, live HTTP/realtime/PHP-to-Python integration, 9 JS SDK tests, 12 Python SDK/lifecycle tests, shared generated contracts in all four languages, TypeScript declarations, Dart analysis, and clean Composer application/core installation from a committed archive. Graph portability is also checked across different source-file paths. GitHub CI and live MySQL/PostgreSQL/MongoDB checks have not been run for this local phase.
