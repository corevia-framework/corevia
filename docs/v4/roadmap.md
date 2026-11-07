# Corevia V4 roadmap

Status: maintainer-approved scope. Workstreams are planned or in progress; none is accepted as complete by this document. V3 remains the implementation baseline. This roadmap does not publish a V4 version or authorize deployment changes.

Implementation has started locally: see [progress and prototype instructions](progress.md) and the [compatibility baseline](baseline.md). A workstream remains incomplete until its exit evidence is satisfied.

Current phase status (2026-11-08): Phase A baseline/design is complete; Phase B local engineering/acceptance is complete, with public package publication still outstanding pending the maintainer's timing decision. See the [design record](phase-a-design.md) and [delivery evidence/publication boundary](phase-b-delivery.md). This does not mark all cross-phase workstreams or release acceptance complete.

## Product direction

Make Corevia easier to install, extend, operate and upgrade while developing its distinctive combination of explicit modules, durable workflows and PHP/Python/frontend/mobile contracts. Feature count alone is not a release criterion. Deliver the complete target scope through reviewable phases, preserving a small PHP application footprint.

The competitive target is to outperform established frameworks on specific, published developer-experience, portability and runtime measurements. Superiority is a goal to demonstrate, not a current claim. Broad hosting support must not require that every host execute every optional capability. See the [hosting and competitive acceptance plan](hosting-and-competitive-targets.md).

## Consolidated scope

| ID | Workstream | Required outcome |
| --- | --- | --- |
| 1 | Application/framework packaging and installer | Separate an application skeleton from versioned framework dependencies; publish supported Composer packages; offer a feature-composition installer with a dependency preview, explicit configuration and conflict detection. Bootstrap/Tailwind/React remain choices rather than mandatory dependencies. |
| 2 | Database development | Application-owned migration history, status and safe execution; seeders/factories; richer prepared queries, joins and pagination; optional model/repository helpers. Specify each driver's capabilities and preserve SQL/document-store differences. |
| 3 | Application testing toolkit | HTTP/auth assertions, isolated database setup, fixtures/factories, controllable time, and explicit mail/job/AI fakes. Test application behavior without live external services by default. |
| 4 | Developer inspection and observability | Development-only inspection of routes, queries, flows and jobs; production health/readiness, metrics and distributed-trace export. Redact credentials, sensitive account data and model content by default. |
| 5 | Web/API development experience | Nested request validation, consistent error envelopes, OpenAPI generation, file-storage adapters and localization primitives. Preserve existing route behavior unless an explicitly documented migration changes it. |
| 6 | Background operations | Scheduler with explicit timezone/overlap semantics, failed-job inspection/retry, queue monitoring and optional shared cache/lock adapters. Document at-least-once delivery and downstream idempotence. |
| 7 | AI application platform | Document ingestion, scalable vector-store adapters, structured output, retrieval evaluations and per-tenant usage budgets. Permissioned tools need explicit authorization, bounded execution, human approval where configured and an isolation model. |
| 8 | Performance and support | Reproducible benchmarks, long-running worker soak tests, crash/recovery tests, dependency/runtime support matrix, security reporting and a published compatibility/support policy. Do not claim superiority without comparable measurements. |
| 9 | Safe upgrades | Preview code/config/starter changes, detect application edits and guide migrations without overwriting user code. Back up matching code/config/data/keys; distinguish reversible changes from changes requiring backup restoration. |
| 10 | Transactional outbox and webhooks | Commit application changes and outbound events atomically where the selected store supports it; relay with retries and delivery history. Provide signed webhooks, replay protection, key rotation and controlled redelivery. |
| 11 | Tenant isolation | Carry server-validated tenant identity through queries, files, jobs, caches and AI retrieval. Enforce boundaries in adapters and contracts, with explicit privileged cross-tenant operations and negative tests. |
| 12 | Public API lifecycle | Versioned contracts/endpoints, scoped and revocable API credentials, quotas, idempotency semantics and documented deprecation. Credential storage and mobile clients must follow their authentication model rather than assuming browser cookies. |
| 13 | Extensible identity | Optional passkeys/WebAuthn, service accounts and organization SSO packages; documented account linking, recovery, revocation and provider configuration. Validate advertised provider flows in dedicated test environments. |
| 14 | Audit and data lifecycle | Record sensitive operations with access controls and integrity requirements; configurable retention, account export and deletion workflows across storage/adapters. Define backup and legal-retention exceptions; no blanket compliance certification claim. |
| 15 | Service resilience | Propagate deadlines and cancellation between HTTP, PHP, Python and jobs; bounded retries, retry budgets and circuit breakers. Require explicit retry safety and supervise work that cannot be interrupted cooperatively. |
| 16 | Extension compatibility | Machine-readable framework/runtime/dependency/capability requirements, deterministic resolution and pre-install compatibility checks. Installation must not run third-party hooks without an explicit trust decision; compatibility is not a security guarantee. |
| 17 | Accessible, multilingual starters | Keyboard and screen-reader support, accessible validation, translated UI/messages and RTL layouts across all three auth stacks. Define and test the accessibility target before acceptance. |
| 18 | Offline/mobile synchronization | Optional authenticated change feeds, resumable cursors, tombstones, bounded offline queues and documented conflict resolution for Dart/Flutter and React Native clients. Enforce tenant access on every synchronization path. |
| 19 | Hosting portability | Tested deployment presets for constrained PHP shared hosting, VPS/dedicated servers, AWS and Azure; build-off-server source bundles; safe public/private directory separation; bounded cron workers; external service adapters; explicit capability diagnostics and platform-specific acceptance. Hosting presets remain separate from runtime/module profiles. |
| 20 | V4 frontend starter redesign | Refresh the default welcome/guide pages, auth/account/recovery screens, dashboard shell and optional AI page with a coherent V4 visual identity across Bootstrap, Tailwind and React. Provide responsive navigation, reusable UI components, light/dark themes and loading/empty/error states; preserve feature parity, safe rendering, CSRF/session behavior and application ownership. Ship prebuilt assets for hosts without Node. |

The tested development installer now supports `app:new` with the built-in web/API/auth/AI selections, three UI choices and explicit database-driver requirements. Other proposed feature combinations, such as automatic realtime infrastructure provisioning, are not implied. See [delivery commands](phase-b-delivery.md).

## Delivery phases

| Phase | Scope | Exit evidence |
| --- | --- | --- |
| A. Baseline and design | Inventory V3 public APIs, user-owned files and persisted formats; specify package/contract ownership, threat models, hosting capability matrix for item 19 and benchmark methodology for item 8 | Recorded baseline, compatibility matrix and accepted designs for cross-cutting changes. |
| B. Distribution and upgrades | Items 1, 9 and 16; shared-host distribution layout from item 19 | Fresh install, feature composition, dependency conflict and edited-application upgrade fixtures pass from distributable artifacts. No application code is silently overwritten. |
| C. Daily application development | Items 2, 3, 5 and 12; welcome pages, UI foundations and dashboard shell from item 20 | A documented reference API/application runs against advertised drivers, with application migrations and tests using the public toolkit. Contract and API upgrade fixtures pass; the frontend foundations work at mobile/tablet/desktop sizes. |
| D. Identity and tenant applications | Items 11, 13, 14 and 17; auth/account frontend work from item 20 | Tenant-escape negatives pass across storage/queues/retrieval; identity enrollment/recovery/revocation and export/deletion flows pass; all three redesigned starters meet the declared accessibility target and browser/security checks. |
| E. Reliable operations | Items 4, 6, 10 and 15; VPS/cloud adapters and bounded cron execution from item 19 | Traces correlate across runtimes; outbox/webhook replay and crash recovery pass; scheduler overlap and worker ownership are verified under concurrency; failure injection demonstrates retry/deadline bounds. |
| F. AI and connected clients | Items 7 and 18; optional AI frontend from item 20 | Real configured model/vector adapters pass supported contracts and budget/isolation checks; mobile synchronization passes disconnect/resume, conflicts, deletion and revoked-access tests; the AI view handles streaming, cancellation and failures accessibly. |
| G. Release acceptance | Finish items 8 and 19 and verify all 20 workstreams | Clean source/package installs, V3-to-V4 staged upgrades and matching backup restoration, required CI, hosting-target acceptance, supported service/runtime integration, soak/load reports and complete operator/user documentation. |

Each phase can contain several pull requests. A phase is complete only when its exit evidence is recorded for the implementation being released. A scope change needs an explicit roadmap update; an untested adapter is not marked complete because its interface exists.

## Frontend redesign acceptance

Item 20 is planned; the current packaging preview still uses existing V3 views. Establish the V4 visual direction before replacing starter templates, then deliver equivalent workflows through each stack's native components. Coordinate accessibility, translation and RTL work with item 17 rather than treating a visual refresh as accessibility acceptance.

- Cover welcome/onboarding, navigation, auth/recovery/account screens and a useful dashboard shell. Display only real application data; represent unconfigured capabilities with clear empty states.
- Define reusable typography, spacing, colors, forms, buttons and feedback states, including light/dark themes and persisted non-sensitive theme preferences.
- Verify mobile, tablet and desktop layouts, keyboard/focus behavior, contrast, reduced-motion preferences, translated text expansion and RTL layouts.
- Run browser checks across Bootstrap, Tailwind and React for sign-in, registration, recovery, account actions and optional AI streaming/error/cancellation behavior. Preserve output escaping, cookie/CSRF protections and CSP-compatible assets.
- Bundle production CSS/JS with dependency licenses and reproducible asset checks; a normal installation must not require a CDN, Node or a frontend build on the hosting server.
- Preview generated-file changes during upgrades. Existing customized views, routes and assets must not be overwritten automatically.

## Architecture and compatibility rules

- Retain deterministic composition, runtime profiles, execution cleanup, architecture checks, reproducible artifacts and generated cross-runtime contracts from V3.
- Keep Python, Node, Redis, databases, vector services and container runtimes optional for applications that do not select their capabilities.
- Additive, compatible improvements may ship in V3.x. Reserve a V4 breaking change for a documented need and migration path; never break APIs simply to increase the major version.
- Separate framework contracts from application policy. Do not hide database differences, promise exactly-once side effects or automatically retry unsafe operations.
- Generate application-owned files additively; preview conflicts. Keep secrets out of generated public assets, logs, contracts, packages and Git history.
- Every advertised runtime/adapter needs explicit support, test and operating limits. Unavailable external validation stays visible as incomplete or explicitly deferred, never reported as passed.

## Completion checklist for every workstream

- Public API/contract, ownership and supported behavior documented.
- Working example/reference application and ordinary developer instructions.
- Meaningful success, failure, concurrency and security-boundary checks as applicable.
- Upgrade/data compatibility instructions, rollback limits and operational requirements.
- Source/version-bound validation results and no unresolved release-blocking finding.

The V4 launch must state which modules and external integrations were actually validated. The current V3 OAuth/Docker deferrals do not automatically transfer into a V4 acceptance claim. Independent security review should be pursued for identity, tenant isolation and tool execution; record its actual scope and disposition without claiming certification.
