# Corevia 4.0.1 - Composer / Packagist compatibility

Corevia 4.0.1 prepares the existing full framework project for standard Composer
distribution under `corevia-framework/corevia`, with package type `project`.

## Changes

- Expand Composer archive exclusions and Git export rules for local credentials,
  generated storage, installed dependencies, caches and machine-specific files.
- Preserve all framework modules, CVP protocols, CLI commands, SDK sources,
  integrations, starter assets, examples and CLI test support in the root project.
- Preserve the CLI executable bit in Git archives for Unix installations.
- Fix `doctor` reporting a mandatory SQLite failure when the supported
  database-free web starter has bootstrapped successfully.
- Add an offline installation regression test covering both Composer and Git ZIPs,
  the `^4.0` version constraint, strict PSR-4 autoloading, configuration, CLI,
  writable private storage and database-free HTTP requests. Add it to the existing
  PHP 8.2, 8.3 and 8.4 CI matrix.
- Close recursive directory handles before deleting private release-test fixtures
  on Windows.
- Document Composer installation and post-installation commands; correct the
  README's stale current-release V3 metadata statement. Historical V3 compatibility
  documentation and unchanged 4.0.0 language SDK versions remain intact.
- Align PHP framework and optional split-distribution build metadata to 4.0.1.

No third-party PHP runtime dependency, framework API or protocol change is included.

## Installation

After this repository and tag have been registered/indexed on Packagist:

```sh
composer create-project corevia-framework/corevia myapp "^4.0"
cd myapp
php corevia doctor
php corevia route:list
php corevia serve
```

Open http://127.0.0.1:8081. PHP 8.2+ with JSON is enough for the default web starter;
no database, Node.js, Python, Redis or Docker is required. Composer needs its normal
ZIP extraction support (PHP ZIP or an external extractor). Optional integrations
retain their existing extension and service requirements.

Configure process environment variables and `config/settings/`; `.env.example`
is a reference and is not automatically loaded. For production, serve only
`public/`, configure HTTPS and give the PHP process write access to private
`storage/`. Do not use the built-in development server for production traffic.

This root project bundles the framework in `packages/`. It is separate from the
existing optional split runtime/application distribution. Existing applications
should review changes in a staging copy and retain application-owned files and
private state; this patch does not introduce an automatic in-place upgrade.

## Validation and limits

See [the preparation report](readiness-4.0.1.md) for exact local checks and remaining
publication gates. Existing [V4 integration validation limits](optional-validation.md)
continue to apply. This packaging patch does not claim new cloud, real identity
provider, mobile-device or security-audit acceptance.

These notes are prepared locally. Publication on GitHub or Packagist requires
separate maintainer approval; a successful local package installation does not
prove that the public registry has indexed the release.
