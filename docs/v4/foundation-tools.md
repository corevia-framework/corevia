# V4 compatibility, hosting and starter-upgrade tools

These local development tools extend the packaging prototype. They do not certify a production deployment, apply an upgrade or publish a V4 release. Rebuild a detached preview to include new runtime commands; an earlier preview's mirrored `vendor/` dependency does not update automatically.

## Compatibility checks

From a repository checkout with the recorded V3 history and PHP's tokenizer extension available:

```sh
python tests/v4/compatibility.py
python tools/v4-compatibility.py
python tests/v4/persistence.py
```

The API gate extracts the actual V3 framework from commit `d7429ed14356de39aaf8e98fa0993fc5c8f38337` and captures its 143 class/interface/trait/enum symbols in a separate PHP process. It compares public/protected method and property shapes, inheritance and constants against current source. Removals, signature changes and new interface/abstract implementation obligations fail conservatively. Framework release-version metadata may change independently of protocol constants.

API snapshots and their fingerprints are written to ignored `storage/v4-api-baseline.json`, `storage/v4-api-current.json` and `storage/v4-api-verification.json`. Capture autoloads trusted framework declarations, not the application bootstrap; this is a development tool for trusted source, not a sandbox for untrusted packages. Literal parameter-default values and behavior are outside its coverage. Legitimate variance changes can need manual review; added concrete methods can still conflict with an application's subclasses.

The persistence fixture creates a paused workflow plus synthetic user/session/account-token data under actual V3 code, resumes/consumes them under current code, restores the exact pre-upgrade JSON backups and repeats under V3. It checks identity, idempotency, checkpoint behavior and token single use. No existing application files or databases are opened. This establishes those JSON fixtures, not every SQL/document schema, arbitrary downgrade or external side effect.

## Hosting diagnostics

```sh
php corevia hosting:check --target=shared
php corevia hosting:check --target=vps
php corevia hosting:check --target=aws
php corevia hosting:check --target=azure
```

Target names are planning labels, not proof that a provider, service or subscription is available. The command inspects this PHP process, extension/function availability, PHP memory/execution limits, the conventional private storage directory and public/private path separation. It does not execute application configuration, create probe files, test credentials, contact hosts or provision infrastructure. It does not infer a web runtime's configuration from CLI PHP.

Optional requirements, stored in a local JSON file:

```json
{
  "extensions": ["pdo_mysql"],
  "features": ["cron", "outbound_https"],
  "minimum_memory_mb": 64
}
```

```sh
php corevia hosting:check --target=shared --requirements=storage/hosting-requirements.json
```

Supported feature names: `cron`, `workers`, `python`, `websocket`, `outbound_https`. An optional `declarations` object can record booleans for those features, `https` and `document_root`. A false required declaration blocks the report. A true declaration remains explicitly **unverified**, not measured acceptance. Never put passwords, tokens or connection strings in this requirements file; unknown fields fail without printing their values. Files are capped at 64KiB.

Use `--public-path=/absolute/path/to/public_html` to inspect a different existing public directory. This does not generate a deployment layout or change document-root configuration. Private storage currently means the conventional application `storage/`; other configured storage paths require separate deployment inspection.

Exit code 1 means a measured prerequisite or explicit required declaration failed. Exit code 0 means the diagnostic completed without a known blocker; unverified requirements can remain. The report always keeps `production_ready` and `target_validated` false. Windows ACLs, actual filesystem write/locking behavior, HTTP private-file denial, rewrite rules, HTTPS, outbound access, cron permissions, external services and provider quotas still require target acceptance.

## Read-only auth-starter upgrade preview

In an application with an installed auth starter and its original inventory:

```sh
php corevia starter:upgrade
php corevia starter:upgrade --path=path/to/application
```

The command compares `.corevia-auth-starter.json`, current application files and the running framework's templates for that same UI stack. It rejects malformed inventories, traversal and symlinks, bounds inventory/comparison sizes, and outputs paths/statuses without file contents or user-file hashes.

| Status | Meaning |
| --- | --- |
| `already_current` | Application file matches the current template. |
| `update_available` | File still matches its original template and a new template differs; candidate for a future reviewed update. |
| `conflict` | Application edits or an unrelated existing file need a merge decision. |
| `deleted_by_application` | An originally generated file was removed; do not silently recreate it. |
| `new_file` | A current template has no original entry and no existing output file. |
| `obsolete` / `obsolete_modified` | An original template no longer exists in the new kit; preserve it pending review. |
| `obsolete_absent` | A removed template is already absent from the application. |

The result has `files_written:false`, `can_apply:false` and a public-template fingerprint. `--apply` and other unsupported options are rejected. Exit 0 means the preview completed, not that conflicts were resolved. This is auth-starter comparison only: no patch application, backups, database migrations, dependency upgrades, AI-starter migration or full application upgrade is implemented here.

## Local checks and CI

```sh
php -n tests/v4/hosting.php
php -n tests/v4/upgrades.php
python tests/v4/compatibility.py
python tests/v4/persistence.py
python tests/v4/packaging.py
```

These checks are configured in the PHP CI matrix. Local success is not a GitHub Actions run. Detached-package tests additionally verify the commands inside real Composer installations, reject private-file HTTP access and check all three auth stacks. The V4 frontend redesign remains planned and is independent of these foundation tools.
