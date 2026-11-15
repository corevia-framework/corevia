# Phase B delivery and publication boundary

Release update: this file records the development increment. The current V4.0 disposition is in the [final scope review](final-review.md), [release notes](release.md) and [deferred-target guide](optional-validation.md). Earlier pending statements are historical, not a claim that unverified targets later passed.

Updated 2026-11-08 (Asia/Karachi). Local engineering acceptance covers composition, dependency checks, staged upgrades and upload artifacts. The maintainer explicitly scheduled public package publication for the final V4 release. Phase B is complete for local delivery; publication is retained as a Phase G release task. No V4 code, release or package has been uploaded by these commands.

## One-command application composition

First build a runtime/application distribution from the intended Git index:

```sh
python tools/v4-package.py --output=storage/v4-ab
php corevia app:new storage/my-v4-app --features=ai --stack=react --runtime-path=storage/v4-ab/runtime
```

`ai` resolves `web -> auth -> ai`. Supported built-ins are `web`, `api`, `auth`, `ai`; API-only conflicts with browser/auth kits. Choose Bootstrap, Tailwind or React with `--stack`. Use `--features=api` for a minimal API application. `--database=json|sqlite|mysql|pgsql|mongodb` chooses the data connection and declares its PHP driver requirement to Composer. It does not create databases, import accounts, switch auth storage or perform schema setup. JSON remains the default; configure SQL auth deliberately and run its explicit migration before use.

Add `--dry-run` to inspect the dependency closure without creating files or invoking Composer. The destination's parent must exist and the destination must be new. Existing files are never overwritten. The running PHP, Composer and selected driver extensions are build-machine prerequisites; the resulting PHP application can be uploaded with dependencies already installed. Composer runs with scripts/plugins disabled, with its log private under the newly created application's storage. A failure retains only that newly reserved directory for inspection.

The runtime inventory validates package-file drift. It does not authenticate a publisher: use a trusted source/package channel. Build/deploy operators remain responsible for reviewing third-party code. No discovery scans or extension hooks execute during dependency planning.

The runtime package also supplies the Composer binary `corevia-new`. Once that package is installed from an authorized repository and Composer's bin directory is on PATH, `corevia-new myapp --features=ai --stack=react` creates an application in one command. It uses PHP/Composer on the build machine and does not require a Python installer. Package installation/public registry availability is not implied by the command being implemented.

## Extension compatibility metadata

`php corevia extension:check catalog.json --features=example` checks metadata only. A catalog is an object keyed by ID; each entry has:

```json
{
  "example": {
    "format": 1,
    "id": "example",
    "framework": {"min": "3.0.0", "before": "5.0.0"},
    "php": {"min": "8.2.0", "before": "9.0.0"},
    "requires": [],
    "provides": ["example.capability"],
    "conflicts": [],
    "extensions": [],
    "hooks": []
  }
}
```

Bounds are inclusive `min`, exclusive `before`, not arbitrary Composer constraint syntax. Dependencies resolve in stable order; cycles, conflicts, incompatible runtimes and ambiguous capability providers fail. Extension prerequisites are reported for target evaluation. Compatibility is not authorization to install or execute third-party code, and executable hooks are rejected. Custom catalog entries are checked by this command; the application installer composes only the tested built-in features.

## Staged upgrades and rollback

```sh
php corevia app:upgrade --from=storage/my-v4-app --output=storage/my-v4-replacement --runtime-path=storage/v4-ab/runtime --dry-run
```

After stopping source writers, backing up external databases/storage/keys and reviewing the plan:

```sh
php corevia app:upgrade --from=storage/my-v4-app --output=storage/my-v4-replacement --runtime-path=storage/v4-ab/runtime --offline
```

This creates a separate replacement. The original remains byte-for-byte unchanged; no traffic switch or in-place deployment happens. `--offline` is an operator declaration, not proof that every external writer stopped. Source-content drift during staging fails the operation. Symlinks and oversized snapshots are refused.

Application PHP/config/routes/views/assets and in-project private state are copied without executing application configuration. Unrelated Composer requirements, repositories and lock pins are retained; only the selected runtime and its dependency closure are updated with scripts/plugins disabled. A customized loader requires a manual merge. Detached runtimes must match their recorded inventory; modified or uninventoried vendor code and older prototypes lacking that inventory require manual migration. The supported legacy migration starts from the recorded V3 framework layout; modified/unknown framework files require explicit fork migration rather than being silently discarded. LF/CRLF differences in baseline framework text are normalized for comparison.

Compiled config/build files can contain old absolute paths. Recognized staged caches are quarantined inside a newly reserved private `storage/.corevia-upgrade-*` receipt directory; recompile them for the replacement. Original caches remain intact. No SQL schema/data migration is applied in this phase. Copies of offline project files do not back up external databases, external key stores, remote volumes or ongoing external side effects.

Before booting the replacement, review environment variables, absolute application paths, mail/model endpoints, storage and database isolation. Arbitrary application code may reference the original deployment explicitly; staging cannot rewrite that policy safely. Run application tests in an isolated environment, then perform a separate operator-controlled deployment. To roll back, restore the matching external data backup and switch to the untouched original code/config/keys; do not point old code at arbitrary new-schema writes.

## Shared-host upload layout

```sh
python tools/v4-shared.py --application=storage/my-v4-app --output=storage/my-v4-upload --dry-run
python tools/v4-shared.py --application=storage/my-v4-app --output=storage/my-v4-upload
```

The artifact contains:

```text
public_html/             # Only document root: bridge, assets and rewrite rules
private/corevia/         # Application/configuration/vendor, outside document root
private/corevia/storage/ # Provision/preserve runtime state separately
```

Dependencies and compiled frontend assets are already included. A normal PHP page/auth request needs no on-host Composer, Node, Python, Docker, daemon or root account. `.env`, live storage and private runtime keys are excluded. Review application code for inline credentials before distributing a bundle; this is not a secret scanner. Additional executable public PHP/hidden files, symlinks and oversized bundles are rejected. Private files are generated with owner-only permission metadata; extraction tools/Windows ACLs and the actual PHP user still require operator verification.

Upload the two sibling directories into a layout where only `public_html` is web-accessible. Do not publish the bundle root. Provision HTTPS, correct document root, private writable persistent storage, database credentials and encryption keys separately. Preserve existing account/mail keys and state when updating an existing deployment. Provider rewrite/AllowOverride, filesystem/ACL, cron, external HTTPS, worker and model restrictions remain target-specific.

Local acceptance serves the bundle through PHP's loopback router and through isolated Apache/FastCGI with the generated rewrite file. These tests establish the recipe's local behavior; they do not certify a real shared provider, VPS, AWS or Azure deployment. The optional Windows Apache fixture uses only its generated config and owned processes. Its backend mapping follows [Apache's FastCGI directives](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html).

## Publication candidate

```sh
python tools/v4-publication.py --distribution=storage/v4-ab --output=storage/v4-publication --base-url=https://example.invalid/corevia-v4
```

Replace the illustrative base URL with the selected real HTTPS endpoint only when publication is authorized. This prepares `runtime.zip`, `application.zip`, `packages.json` and SHA-256 checksums for `dev-v4`; it never uploads, registers a package or publishes a stable V4. The application candidate uses a Composer repository rather than a developer-local path. A real Composer consumer test installs the runtime from prepared ZIP metadata.

Publishing still requires a maintainer-selected endpoint/channel, actual upload, repository access and independent consumer installation from that endpoint. Packagist registration is separate: the runtime's generated composer.json differs from this monorepo's project-root composer.json, so registering this root as the runtime would be incorrect. Use a proper split repository or the prepared static Composer channel. See [Composer repository documentation](https://getcomposer.org/doc/05-repositories.md).

## Exit evidence

- `php -n tests/v4/features.php`: closure/order, runtime bounds, conflicting features, cycles, ambiguous capabilities and hook rejection.
- `python tests/v4/phase-b.py`: real package installation; all three UI stacks; API-only routing; dry-run/no-overwrite; actual V3 migration; edited code/private state preservation; unrelated dependency pins; disabled scripts; stale-cache quarantine; customized-fork refusal; shared HTTP auth/assets/private-file denial; ZIP/repository consumer installation.
- `python tests/v4/apache.py <generated-shared-directory>`: optional isolated Windows Apache/FastCGI recipe validation when those binaries are available.
- Existing API, persistence, architecture, SDK and PHP regressions remain required.

Phase B's local exit fixtures pass and the maintainer-approved delivery scope is complete. Registry publication remains explicitly unperformed and is scheduled for the final V4 release in Phase G, alongside real hosting/release-scale acceptance. The frontend redesign is still Phase C/D/F work.
