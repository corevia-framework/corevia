# Corevia 2.0.0

Corevia V2 provides a standalone PHP framework with a database-free starter website, explicit module/action lifecycle, durable workflows and language-neutral connectivity.

## New in V2

- Kernel readiness/shutdown hooks, module manifests, capability discovery, cached configuration and lazy service bindings.
- Durable pause/resume, human/event checkpoints, retry policies, output contracts, compensation and execution timelines.
- CVP/2 alongside CVP/1, authenticated remote action gateways, signing hooks and bounded local worker transports.
- Authorized private/presence channels, public wildcard subscriptions, heartbeat/reconnect metadata and optional pub/sub adapters.
- Shared PHP, JavaScript, Python and Dart protocol contracts; thin React and React Native integration.
- Named PDO connections for MySQL/MariaDB, PostgreSQL and SQLite, prepared query builder, nested transactions and portable SQL flow snapshots.
- Optional MongoDB and local JSON document storage with extensible connector contracts.
- Responsive starter pages, routes/web.php, controllers, escaped PHP views, named routes, groups, constraints, resources, redirects and signed CSRF forms.
- CLI trace inspection, scheduled jobs, database checks/migration, configuration caching and read-only upgrade checks.

## Quick start

```powershell
composer install
php -n corevia serve
```

Open http://127.0.0.1:8081. No database is required. Configure databases in config/settings/database.php; see docs/v2/databases.md and docs/v2/web.md. The legacy SQLite checkout demonstration remains at /demo.html.

## Validation and migration

Automated checks cover PHP 8.2/8.3/8.4, the representative V1 application fixture, live HTTP/WebSocket/worker integrations, database-free execution, shared SDK contracts, real MySQL/PostgreSQL/MongoDB service contracts, and clean Composer skeleton/core installs. Desktop/mobile browser checks cover starter navigation, form submission and layout overflow.

Read docs/v2/migration.md before upgrading. CVP/1 remains available. Remote endpoints outside loopback need authentication/signing. The root homepage now uses web routes; configure application authentication separately from the example CSRF middleware.

## Boundaries

In-process PHP deadlines are cooperative. Parallel execution and external pub/sub require explicit adapters. JSON queues/storage and presence membership are single-node facilities. Reconnect restores subscriptions without offline event replay. Crashed uncertain external side effects require operator reconciliation. MongoDB stores application documents; workflow persistence uses JSON or a selected SQL RunStore.

The repository and tagged source support private VCS/path installation. Public Composer/npm/Python/Dart registry publication is separate and has not been performed. Production deployment, secrets, TLS, application schemas and access policies remain explicit operator choices.

V1 is recoverable at v1.0.0. V2 is merged through PR #1 and identified by the annotated v2.0.0 tag.
