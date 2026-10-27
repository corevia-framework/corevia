# V2 release report

Prepared release version: 2.0.0. Work is delivered as scoped commits on develop/v2 and reviewed through release/v2.0.0 and PR #1. The stable merge and tag await explicit approval. The annotated v1.0.0 tag and current main preserve the original baseline. Public package registries are not published automatically.

## Validation

- 21 original PHP regression tests and 14 kernel tests.
- 8 flow failure/persistence groups, including compensation failure, duplicate delivery, paused-run definition checks, output rollback, deadlines, and parallel merge conflicts.
- 13 shared CVP/2 fixtures in PHP, JavaScript, Python and Dart.
- Real local-process round trip, worker crash and timeout tests.
- Scheduled job, read-only upgrade, representative V1 fixture and PDO query-diagnostic checks.
- Protocol depth/body limits, unknown message rejection, corrupt-store preservation and queue-size security checks.
- Original 8 database-free task tests and optional SQLite task-adapter check.
- 9 JavaScript and 7 Python SDK tests, Dart static analysis and HTTP/realtime reconnect checks.
- Original 8 live integration groups and 4 database-free HTTP/CLI groups.
- Live CVP/2 JavaScript → PHP → Python authentication, correlation and network-deadline checks.
- Live presence identity, duplicate-tab membership, public wildcard/private boundary and stale-heartbeat checks.
- Clean Composer create-project skeleton and standalone core installs from committed archives, with database-free smoke execution.
- Prepared queries, nested transactions and portable workflow persistence against real MySQL 8.4, PostgreSQL 16 and SQLite, plus MongoDB 7 and local JSON document contracts.
- Named web routes, controller injection, templates, CSRF, validation, source-file denial, HEAD and same-origin forms on a custom port.
- Real desktop/mobile Chrome navigation and form submission, with no horizontal layout overflow.

CI runs the PHP 8.2/8.3/8.4 matrix, Node/Python integrations, clean installs, Dart and real database-service checks. The database/web feature gate passed in [GitHub Actions](https://github.com/corevia-framework/corevia/actions/runs/40572145709); final version metadata is checked again before merge. No native mobile build, React renderer, external pub/sub deployment or production TLS deployment was tested here.

## Operational boundaries

PHP action deadlines are cooperative; use bounded remote or process transports for blocking work. Parallel execution is an explicit optional adapter contract, with no automatic infrastructure startup. JSON persistence and scheduled jobs are single-node facilities. A crash leaving an uncertain side effect requires inspection and reconciliation, never automatic replay. Presence membership is node-local even when an external fan-out adapter is used. Event reconnect restores subscriptions but does not replay offline history. These boundaries are documented in flows.md, realtime.md, protocol.md and tooling.md.

Package manifests and local installation flows are prepared; the public Composer/npm/Python/Dart registry identities are not published by this implementation. Keep private repository authentication configured when installing through VCS repositories.

## Stable release acceptance

The supplied PDF says “Merge V2 only after V2 is complete and accepted” and places the stable tag after the final merge. Final CI passed for the database/web expansion and stable metadata. Explicit approval for the main merge, v2.0.0 tag and GitHub release is pending. After approval, merge through PR #1 and tag the accepted merged main commit, preserving V1 history without force-pushing. See RELEASE_NOTES_V2.md for release contents and deployment boundaries.
