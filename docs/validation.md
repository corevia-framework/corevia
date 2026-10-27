# Local validation results

## Database and web delivery — 28 October 2026

New local checks passed for JSON documents, SQLite query/transaction/workflow persistence, named routes/controllers/views, signed CSRF, validation, same-origin forms, HTML escaping and private-file denial. Real Chrome checks passed at desktop and mobile widths, including native form submission with no horizontal overflow. PHP lint passed for all 132 files. The complete original PHP and live checkout/realtime/task regression suites still pass.

Hosted [CI run 40572145709](https://github.com/corevia-framework/corevia/actions/runs/40572145709) passed all PHP matrix, Dart and database-service jobs, including real MySQL 8.4, PostgreSQL 16 and MongoDB 7 contracts. These services were provisioned in CI; none was installed as a mandatory dependency on the local machine. The final stable metadata is validated again before tagging. Public registry publication and production deployment were not performed.

## V2 remaining milestones — 28 October 2026

The local release gate passed: 21 original PHP tests, 14 kernel tests, 8 flow groups, 13 shared CVP/2 fixtures, process timeout/crash checks, queue/upgrade/query/security checks, and the original database-free learning app and SQLite adapter tests. JavaScript passed 9 tests and Python 7; Dart static analysis, shared fixtures, authenticated HTTP and realtime reconnect checks passed using a workspace-local official SDK. Live CVP/2 JS→PHP→Python and presence/wildcard/heartbeat checks passed, as did the original 8 cross-process groups and 4 learning HTTP/CLI groups. PHP lint passed for 108 source/test files.

Real clean Composer installs from committed archives passed for the skeleton and standalone core package, with PDO-disabled smoke tests. These use local path repositories; public packages are not published. The reachable-history credential-pattern scan is recorded separately and is not an exhaustive secret audit. Hosted CI and owner acceptance remain separate release gates. See `v2/release.md` for operational boundaries and the review report.

## V2 M1 validation — 4 October 2026

All local checks passed on `develop/v2`: 21 existing PHP tests, 14 new kernel tests with PHP extensions disabled, 8 database-free learning-app tests, the optional SQLite task adapter test, 7 JavaScript SDK tests, 5 Python SDK tests, 8 cross-process integration groups, and 4 learning HTTP/CLI groups. Syntax validation passed for 87 PHP files. Composer metadata validation passed with a process-local Git safe-directory setting because the sandbox-created repository and local shell have different owners.

The database-free kernel example ran with `php -n` and produced the expected greeting and lifecycle order. CI includes the new kernel suite and example; hosted CI status has not been verified. No MySQL server was used. See `v2/phases.md` for milestone scope.

Validated on 3 October 2026 in the requested workspace, using Windows, PHP 8.3.35, Node.js 22.14.0 and Python 3.13.3.

| Check | Result |
| --- | --- |
| `composer validate --strict` | Pass |
| `composer install --no-interaction --prefer-dist` | Pass; lockfile and autoload generated, no external PHP packages required |
| PHP lint | 60 PHP executable/source/test files passed |
| PHP test suite | 21 tests passed |
| JavaScript SDK suite | 7 tests passed |
| Python SDK suite | 5 tests passed |
| Cross-process integration | 8 check groups passed |
| CLI `doctor`, `routes`, `modules`, `flow:list` | Pass |

Integration check groups:

1. PHP → Python CVP/1 remote action call.
2. WebSocket handshake and public/private subscription authorization.
3. Origin denial, control ping/pong, text fragmentation, payload size rejection and frame rate limiting.
4. HTTP checkout → Python fraud check → preserved order with notification warning → JavaScript realtime event.
5. Persistent duplicate flow requests, changed-input conflict and critical payment failure.
6. CLI persisted run inspection/manual notification retry and authenticated JavaScript action gateway call.
7. Real WebSocket reconnect, subscription restoration and subsequent event reception.
8. CLI commands and generated PHP syntax, overwrite rejection and unsafe generator name rejection.

The first Composer install attempted advisory/filter metadata access. Network access was restricted and the Composer cache directory outside the workspace was unwritable, so Composer emitted warnings; installation still exited successfully because there are no third-party PHP packages. The lockfile is included for repeatable installation.

Hosted CI, a browser automation run, native mobile execution, production TLS/proxy deployment and a production payment/email provider were not run. The React hook is optional and was not exercised in a React renderer. Heartbeat/stale-client implementation is included; explicit heartbeat expiration is not part of the timed local integration checks. See deployment documentation for V1 operational limits.

## Learning project added on 4 October 2026

`test-project/` is a separate database-free task application using the same framework. Its eight tests passed with `php -n` (PDO/database extensions disabled). Four real HTTP/CLI check groups passed with the same minimal PHP configuration, covering the learning page, flows/failures, task completion/file persistence and shared CLI data. The optional PDO task adapter passed a separate in-memory SQLite check; no MySQL server was used. The existing 21 framework tests still passed, and lint passed for all 78 PHP files. UI JavaScript was syntax-checked and HTML was served over HTTP; browser interactions were not automated. Hosted CI was updated but has not run here.
