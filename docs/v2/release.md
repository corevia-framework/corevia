# V2 release candidate report

Development version: 2.0.0-alpha.2. Work is delivered as scoped commits on develop/v2 and prepared for review on release/v2.0.0. Main and the annotated v1.0.0 tag preserve the original baseline. No stable merge, stable tag, registry publication, or automatic dependency update occurs before acceptance.

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

CI runs the PHP 8.2/8.3/8.4 matrix, Node/Python integrations, clean installs and Dart checks. Hosted results must be checked separately before a stable merge; local validation does not substitute for branch protection or owner acceptance. No native mobile build, React renderer, external pub/sub deployment, MySQL service, or production TLS deployment was tested here.

## Operational boundaries

PHP action deadlines are cooperative; use bounded remote or process transports for blocking work. Parallel execution is an explicit optional adapter contract, with no automatic infrastructure startup. JSON persistence and scheduled jobs are single-node facilities. A crash leaving an uncertain side effect requires inspection and reconciliation, never automatic replay. Presence membership is node-local even when an external fan-out adapter is used. Event reconnect restores subscriptions but does not replay offline history. These boundaries are documented in flows.md, realtime.md, protocol.md and tooling.md.

Package manifests and local installation flows are prepared; the public Composer/npm/Python/Dart registry identities are not published by this implementation. Keep private repository authentication configured when installing through VCS repositories.

## Stable release acceptance

The supplied PDF says “Merge V2 only after V2 is complete and accepted” and places the stable tag after the final merge. Review the candidate and passing CI, accept these operational boundaries, then merge through a pull request and tag the accepted main commit v2.0.0. Do not rewrite the preserved V1 history or force-push. This report describes a reviewable development candidate, not an already accepted stable release.
