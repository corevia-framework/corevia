<p align="center"><img src="../brand/corevia-logo.png" width="150" alt="Corevia"></p>

# Corevia 4.0.0

Corevia 4.0 is a versioned source/runtime release for PHP 8.2+ web applications,
APIs and optional workers, identity, AI and connected clients. The default site
runs without a database or frontend build service. Optional services require
their own drivers, credentials and operational setup.

## What ships

- Runtime/application ZIPs and a static Composer repository generator; feature
  composition for web/API/auth/AI, three auth UI stacks and staged upgrades.
- SQL migrations/query helpers, application test doubles, validation/OpenAPI,
  private files, localization, scoped API credentials and tenant-aware adapters.
- Optional identity/audit/account-data primitives, SQL scheduling and queues,
  transactional outbox/webhooks, bounded resilience, telemetry and work budgets.
- Optional AI/retrieval/structured-output/permissioned-tool contracts and bounded
  JS/Dart offline synchronization. Trusted tool execution does not isolate hostile code.
- Prebuilt Bootstrap/Tailwind/React assets and a separate synthetic Android lab.

See the [20-workstream review](final-review.md) for limits. No comparative
performance superiority, blanket compliance, provider certification or
exactly-once external side effects are claimed.

## Installation and ownership

Download the `v4.0.0` GitHub source ZIP, or clone that tag and run `composer install`
then `php corevia serve`. PHP with JSON is the only requirement for the basic site.
Composer packages/SDK names are not registered on Packagist, npm, PyPI or pub.dev.
Use the source checkout or release ZIPs; do not assume a registry package exists.

For side-by-side installation, download `corevia-4.0.0-distribution.zip`, extract
its runtime/application directories, and run Composer inside the application.
The separate `runtime.zip`, `application.zip` and `packages.json` assets form an
explicit static Composer repository; its application ZIP resolves dependencies
through the release URL. SHA-256 checksums accompany the assets.
Build/install tooling never enables third-party hooks by default.
Check the asset inventory and [upgrade guide](upgrade.md) before replacing an app.

Keep code/config/storage/vendor private; expose only `public/` or the generated
`public_html/` directory. PHP's development server is for development. Python,
Node, database daemons, queue workers and realtime servers are separate optional
processes, not processes every shared host is expected to run.

## Validation and deferred targets

The [release validation matrix](release-validation.md) distinguishes automated
fixtures, private Linux/VPS/service acceptance and the Android 11 OpenGL lab.
The [optional validation guide](optional-validation.md) explicitly lists tests
not performed and gives consumers a target checklist. Those targets are
unverified and their deployment recipes are starting points, not certification.

Internal tests/review do not replace an independent security/accessibility audit
or intended-workload staging. Before deployment, configure application authority,
secrets, TLS, persistence, backups, supervision and monitoring for the chosen host.

## Compatibility

CVP/1 and CVP/2 remain supported; there is no CVP/4 protocol. The V3 public API
shape and persisted-format upgrade/restore fixtures remain acceptance gates.
New V4 modules are opt-in. Read [upgrade instructions](upgrade.md), including
source-bound artifact recompilation and application-owned schema/data migration.

## Release checks

`php corevia release:check` validates locally supplied V4 source/test and exact
commit CI evidence; it does not query GitHub or certify a deployment. A local pass
alone cannot mark CI passed. `docs/v4/acceptance.json` names mandatory checks and
explicit deferrals; local tests, package/upgrade checks and CI cannot be deferred.
The support status concerns this release's stated scope. Provider/device-specific
acceptance remains the application's responsibility until explicitly recorded.
