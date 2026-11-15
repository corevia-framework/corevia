# Upgrade to Corevia 4.0

Upgrade in an isolated copy first. Keep the current site's code, configuration,
data and keys together as a matching rollback backup. Do not edit a live V3
installation in place or silently replace customized application files.

1. Identify the installed version, runtime/package provenance, enabled modules,
   drivers, workers, contracts and application-owned modifications.
2. Stop writers for a consistent backup. Copy private storage, environment,
   signing/encryption keys and matching code; back up external databases with
   that database's tools. A source ZIP does not back up a database.
3. Build/install V4 separately. Use `app:upgrade --dry-run` with a verified built
   runtime to inspect the staged replacement. Follow `php corevia help` and
   [the delivery guide](phase-b-delivery.md); never target an existing app as a
   new application directory. Unsupported framework forks require manual review.
4. Preserve app routes/views/config, custom dependencies and private state. Run
   application-owned schema migrations deliberately and review data/DDL rollback
   limits; adapters do not convert application schemas or SQL into MongoDB.
5. Recompile source/config-bound artifacts for V4 and the destination paths.
   Preserve the required private signing/encryption keys; do not deploy stale
   compiled artifacts copied from another checkout or host.
6. Run application tests, staged install/restore checks and selected DB/provider
   checks. Verify login/session/recovery, tenant authority, routes, uploads,
   queues/outbox, mail policy and reconnect behavior before switching traffic.
7. Switch traffic and restart long-running workers against the matching code,
   configuration and artifacts. Monitor failures and ownership/lease behavior.

Rollback restores the matching original code/config/artifacts and data backup.
Copying old PHP files over new database state is not a rollback plan. Side effects
already sent to external services require reconciliation and downstream idempotence.

The release retains V3 public API-shape and saved-state fixtures. CVP/1 and CVP/2
are unchanged. V4 release checks use `docs/v4/acceptance.json` and private
`storage/v4-verification.json` / `storage/v4-ci-verification.json`; V3 evidence is
not a V4 acceptance pass. SDK/framework metadata becomes 4.0.0; the Dart SDK
language constraint remains a Dart version constraint, not the framework version.

Read [unverified targets](optional-validation.md) for integrations requiring your
own staging environment. No real production application directories are inspected
or changed by the framework's isolated upgrade fixtures.
