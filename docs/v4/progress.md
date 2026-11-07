# V4 implementation progress

Updated 2026-11-07 (Asia/Karachi). Work is local on `develop/v4`. No V4 push, registry publication, tag, release or deployment is included in this increment.

## Phase A: started

The [compatibility/ownership baseline](baseline.md) records the V3 source reference, critical existing contracts, package boundaries and artifact trust limits. Full public-API/schema inventory, threat models, measured benchmarks and the hosting capability implementation remain outstanding. Phase A is not complete.

## Phase B: first packaging prototype implemented

- `tools/v4-package.py` builds deterministic runtime/application ZIPs and SHA-256 inventories from explicitly selected Git-index files. Preview mode writes nothing. Existing output directories, output outside repository storage and selected indexed symlinks/conflicts are rejected.
- The application skeleton has its own Composer metadata, `App` autoloading and `tests/run.php` smoke check through `php corevia test`. The experimental `corevia-framework/runtime:dev-v4` dependency installs under `vendor/`; no framework `packages/` directory is copied into application ownership.
- Starter templates resolve from framework code. Bootstrap, Tailwind, React and the AI starter operate with detached dependencies while retaining V3 checkout support and overwrite refusal.
- Signed artifacts include detached runtime and executable Composer loader sources; dependency drift causes verification failure.
- Database-free routes and real loopback HTTP auth/CSRF/assets/private-file checks pass for all three UI stacks. AI is installed disabled; no external model or provider was contacted by these checks.
- The packaging integration test is added to the existing PHP CI matrix. CI has not been run for local V4 changes; this is a configured check, not a GitHub pass claim.

This is not the complete Phase B installer or safe-upgrade system. Missing: registry publication, feature composition/dependency solving, extension manifests, edited-application upgrade planning, a ready-to-upload shared-host layout and target hosting acceptance. Phases C-G remain planned.

## Try the prototype locally

Requires a Git checkout, Python 3.10+, PHP 8.2+ and Composer on the development/build machine. Add intended source changes to the Git index before building; an ordinary committed checkout already meets that requirement. The application runtime itself needs only PHP/JSON for the initial pages.

```sh
python tools/v4-package.py --output=storage/v4-preview --dry-run
python tools/v4-package.py --output=storage/v4-preview
cd storage/v4-preview/application
composer install --no-interaction --no-scripts --no-plugins
php corevia serve
```

Open http://127.0.0.1:8081. Choose a new output directory if one already exists; the builder never overwrites it. Keep the sibling `runtime/` directory during this local path-repository installation; Composer mirrors it into `vendor/`. The package is not yet on Packagist. Use `php corevia starter:auth --stack=bootstrap` (or `tailwind`/`react`) in a fresh generated application to add authentication.

## Verification commands

```sh
python tests/v4/packaging.py
python tools/architecture.py
php tests/v3/composition.php
php tests/v3/artifact-boot.php
php tests/v3/operations.php
php tests/v3/auth.php
php tests/features/web.php
node tests/v3/profiles.mjs
```

Acceptance artifacts remain ignored under `storage/`. Existing generated auth files in the working application were not incorporated into the skeleton or overwritten. Pre-existing artifact/web/profile test cleanups now remove only their own named temporary fixtures recursively, so nested auth storage no longer produces cleanup warnings.

The full regression run exposed transient Windows destination-file contention during concurrent identity updates. JSON auth now retries only the same prepared atomic rename for a bounded interval on Windows, retaining the lock and never deleting the committed file or rerunning the transaction callback. Nested auth-storage fixture cleanup was corrected in artifact, web and profile tests.
