# V4.0 release scope and 20-workstream review

Review date: 15 November 2026. This is an internal implementation/evidence review,
not an independent security audit or certification. The maintainer authorized
publishing the supported source release while explicitly documenting unavailable
target tests. The scope below supersedes early roadmap statements that every
provider/device must be accepted before the source release.

| ID | Delivered scope / evidence | Release limit |
| --- | --- | --- |
| 1 Packaging | Aggregate runtime/application ZIPs, Composer path/static-repository installs, web/API/auth/AI composition and all three UI choices; detached install fixtures | No public registry registration or automatic infrastructure provisioning claimed |
| 2 Databases | Application migrations/status/checksums, prepared joins/pagination, seeding/repository helpers and optional SQL/document adapters; SQLite and real service contracts | Application schemas, unsupported DDL and cross-driver conversion remain application-owned |
| 3 Testing | HTTP/auth/database fixtures, controllable time and explicit mail/job/AI doubles | Fakes do not establish real-provider acceptance |
| 4 Observability | Development-only inspection, private health/metrics, trace propagation, bounded OTLP export and actual Collector receipt | Authenticated collector backends, TLS/persistence and long outages require deployment acceptance |
| 5 Web/API | Nested validation, error envelopes/OpenAPI, private files/localization and reference routes | Custom route/schema and upload policy require application tests |
| 6 Background | SQL scheduling/timezones/DST, overlap/fencing, failed jobs/retries and bounded batches | At-least-once delivery; downstream idempotence and process supervision are required |
| 7 AI | Optional ingestion/revisioned retrieval, SQL/Qdrant adapters, structured-output validation, budgets/evaluation helpers and permissioned tools | Model quality is consumer-tested; trusted callbacks/processes are not a hostile-code sandbox |
| 8 Support/performance | Reproducible baseline, bounded lifecycle/RSS and killed-worker recovery fixtures; runtime CI and security reporting | No comparative speed claim, independent audit, multi-hour SLO or physical-host failover claim |
| 9 Upgrades | Separate staged replacements, previews, edited-app preservation, backup/restore fixtures and source-bound recompilation | No in-place live-site upgrade or automatic database rollback |
| 10 Outbox/webhooks | Atomic selected-SQL outbox, delivery history, signatures/replay reservation and controlled retry/redelivery | External effects need reconciliation; shared key rotation/retention is operator policy |
| 11 Tenants | Current server authority across SQL/documents/files/cache/jobs/retrieval with negative contracts | Privileged administration and trusted extension code remain explicit boundaries |
| 12 API lifecycle | Versioned contracts, scoped/revocable credentials, quotas, idempotent receipts and generated clients | Server authorization remains mandatory; offline stores never persist bearer tokens |
| 13 Identity | Optional WebAuthn verifier/virtual-authenticator tests, machine credentials and provisioned organization assurance | Real IdP and physical authenticator acceptance is unverified |
| 14 Audit/data | Integrity-linked audit/checkpoints and bounded account export/erasure/holds | Application adapters, backups, retention and legal policy need owner review; no compliance certification |
| 15 Resilience | Cooperative budgets/cancellation, bounded retries/quotas/circuit breakers, trace context and authenticated single-worker controls | No forced interruption of blocked native work or cross-replica durable cancellation mailbox |
| 16 Extensions | Machine-readable requirements, deterministic resolution and pre-install conflict/hook checks | Compatibility metadata is not a trust or isolation guarantee |
| 17 Accessibility/locales | Automated browser/Axe/keyboard, theme and multilingual/RTL checks across all auth stacks | Manual screen-reader/assistive-technology and independent accessibility audit not completed |
| 18 Mobile/offline | Bounded JS/Dart queues, current-auth transports, conflicts/tombstones and actual Android 11 OpenGL HTTPS/restart/basic-UI lab | React Native/iOS, other rendering modes, production encrypted/multi-owner storage and background policies are unverified |
| 19 Hosting | Capability diagnostics, private/public bundles, shared/VPS/AWS/Azure recipes, local Apache and private Linux nginx/FPM acceptance | Commercial shared hosts/clouds are unverified; feature availability follows host capabilities |
| 20 Frontend | Responsive welcome/workspace, three auth/account stacks, optional AI UI, themes, RTL/localization and prebuilt offline assets | App branding/customizations are application-owned; automated checks do not certify every browser/assistive device |

## Evidence and review disposition

The final release candidate must pass the complete source-bound local suite,
detached package/upgrade checks and required GitHub CI on its actual commit.
Historical receipts for real databases, Qdrant, Collector and private Linux/VPS
work are described in [progress](progress.md); do not apply stale source hashes
automatically after changing an adapter. Current CI repeats supported database
contracts. The selected Android run records split full-run/focused-UI evidence
explicitly and retains its original failure rather than rewriting history.

Internal boundary review checks application ownership, output escaping/CSRF,
current authority, tenant predicates, scoped storage/credentials, retry safety,
secret redaction and trusted process/extension execution assumptions against the
existing negative tests and architecture rules. No untrusted model output gains
tool authority by itself. Keys/config/storage remain private; only `public/` is
served. Native fixture signing keys and raw reports stay outside Git.

The [upgrade guide](upgrade.md) requires matched backups and staged replacements.
The [support/deferred matrix](optional-validation.md) names unperformed targets,
including independent assurance work. Neither this review nor the stable version
number certifies arbitrary application code, providers or operational workloads.
