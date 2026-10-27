# V2 milestone progress

Scope used for this request: phase 1 is V2 milestone M0 (preserve V1), and phase 2 is M1 (kernel/module lifecycle and compatibility).

## Phase 1: complete

- Preserved the remote's initial commit as an ancestor.
- Committed the validated V1 source as `d381026` on `main`.
- Published the annotated `v1.0.0` tag.
- Created `develop/v2` for incremental V2 development.
- Added local environment, credential, and private-key patterns to `.gitignore`.

## Phase 2: complete

- Explicit kernel states, timing records, application hooks, and shutdown.
- Optional module manifests and dependency-ordered boot/ready/shutdown.
- Failed-module isolation and reverse cleanup of dependent modules.
- Compatible container bindings plus lazy factories and singletons.
- Configuration overlays, freezing, and validated JSON caching.
- Explicit capability registry without starting optional infrastructure.
- HTTP/CLI shutdown integration and a database-free lifecycle example.
- Kernel regression suite and CI commands for that suite and example.

## Remaining milestones

| Milestone | Status | Evidence |
| --- | --- | --- |
| M2: flows, retries, compensation, persistence | Implemented and locally validated | 8 flow tests, durable JSON and SQLite adapters, failed-step isolation |
| M3: CVP/2 and remote transports | Implemented and locally validated | 13 shared fixtures, bounded process crash/deadline checks, authenticated HTTP bridge |
| M4: realtime V2 | Implemented and locally validated | Live presence/auth/wildcard/heartbeat tests plus original reconnect/security integration |
| M5: SDK contracts | Implemented and locally validated | PHP/Python/JS/Dart fixtures, JS→PHP→Python HTTP, Dart HTTP/reconnect |
| M6: CLI and observability | Implemented and locally validated | Scheduled jobs, inspector, query diagnostics, config/upgrade tools and V1 fixture |
| M7: release hardening | Local release gates implemented | Clean Composer skeleton/core installs, security and compatibility checks; hosted CI is reported separately |
| M8: stable release | v2.0.0 delivery | Final CI, PR #1 merge into main, annotated v2.0.0 tag and release notes |

See release.md and RELEASE_NOTES_V2.md for test evidence, database/web additions, release procedure and runtime boundaries. V1 remains recoverable at v1.0.0; the accepted V2 release is delivered through PR #1 and v2.0.0.
