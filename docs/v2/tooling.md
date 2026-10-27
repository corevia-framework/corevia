# CLI and observability

`php corevia about` reports the framework and protocol versions without booting a database. `upgrade:check` scans application PHP files and reports possible migration work without editing files. It is a conservative source scan, not proof that arbitrary application behavior is compatible; run your own integration tests. `make:event Name` generates a JSON-serializable event and refuses overwrites, like the other generators.

`flow:inspect <id>` prints the persisted run with state, step status, attempt timing and exception-class metadata, timeline, checkpoints, and compensation results. Existing `flow:show` remains an alias. `flow:resume <id> [--event=name]` resumes a checkpoint from the trusted CLI. Do not expose this command as an unauthenticated approval endpoint.

`worker:dispatch <flow> --input=file.json [--delay=seconds]` stores a scheduled job in private storage. `worker:start --once` handles at most one due job; omit --once to poll. Unix SIGINT/SIGTERM stops the loop gracefully. Each job is claimed under an exclusive file lock and reserves an idempotent flow key. A killed worker leaves a running job for inspection; it is never silently retried. The file queue is for local development and small single-node deployments. Inspect uncertain effects and explicitly reconcile them before retrying through application code.

`config:cache [--env=production]` reads `config/settings/*.php` and `config/settings/environments/<env>/*.php`. The bootstrap uses this environment-specific cache. `cache:clear` removes only that named configuration cache, preserving application data and other environments. APP_ENV defaults to development. Environment templates are documentation; Corevia does not automatically load .env files.

`php corevia test` runs all PHP framework and V2 regression suites. SDK and live integration commands are listed in CI. `update:check` delegates to Composer's read-only outdated report; `--offline` reports the current development version without contacting registries. Neither installs an update. Use Composer explicitly after reviewing compatibility.

The bundled checkout demo still uses SQLite. Database-free applications use MemoryRunStore or JsonRunStore, and the learning application remains available through `php test-project/console.php serve`. Optional query diagnostics are described in `observability.md`.
