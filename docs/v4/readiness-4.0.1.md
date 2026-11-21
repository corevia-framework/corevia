# Corevia 4.0.1 preparation report

Prepared on 21 November 2026. This is a local packaging-readiness report, not a
publication receipt or independent security certification.

## Repository and scope

- Fetched `origin/main` from `https://github.com/corevia-framework/corevia.git`.
- Created `release/v4.0.1` from `a1075e969b7919ce355baade8881ce58a6f112f8`,
  the current upstream main commit at preparation time.
- No release commit, push, merge, tag, GitHub release or Packagist submission was
  performed. Files are staged for review; user-generated authentication files
  remain untracked and unchanged.
- Preserve the complete V4 architecture, modules, SDKs, CVP/1 and CVP/2 protocols,
  integrations and public APIs. This patch adds no third-party runtime dependency.

## Files changed

| File | Change |
| --- | --- |
| `composer.json` | Expand archive exclusions; retain package name, type, requirements, PSR-4 mapping, CLI binary and existing test/lint scripts |
| `.gitattributes` | Matching Git export exclusions for GitHub/Packagist distribution archives |
| `corevia` | Git executable mode changes from 100644 to 100755; file content unchanged |
| `packages/core/src/Version.php` | PHP framework reports 4.0.1 |
| `packages/cli/src/Console.php` | Fix `doctor` falsely requiring SQLite after the supported database-free bootstrap succeeds |
| `tools/v4-package.py` | Optional split-package artifacts use 4.0.1 |
| `tools/v4-acceptance.py` | Workspace-install version metadata follows 4.0.1; include root-project installation acceptance |
| `docs/v4/acceptance.json` | Existing release gate targets 4.0.1 and requires root-project installation acceptance; old evidence is not relabeled as new acceptance |
| `tests/v4/composer-project.py` | New isolated root-project archive/install/CLI/HTTP regression test |
| `.github/workflows/ci.yml` | Run that regression test in the existing PHP 8.2/8.3/8.4 matrix |
| `tests/v3/release.php` | Close directory iterators before Windows private-fixture cleanup and retry transient directory deletion |
| `README.md` | Composer and post-install instructions, publication prerequisite, configuration/storage guidance and current version correction |
| `CHANGELOG.md` | 4.0.1 entry describing only implemented changes |
| `docs/v4/release-4.0.1.md` | Proposed GitHub release notes |
| `docs/v4/publish-4.0.1.md` | Approval-gated GitHub/Packagist commands and public installation checks |
| `docs/v4/readiness-4.0.1.md` | This report |

`composer.lock` needs no change: requirements and dependency resolution are unchanged
and Composer strict validation accepts the existing lock. No package manifest
`version` field is added; Packagist will infer 4.0.1 from the approved Git tag.

## Packaging audit and improvements

- Name remains `corevia-framework/corevia`; type remains `project`.
- Requirements remain PHP `>=8.2` and `ext-json`; no mandatory SQL, MongoDB,
  OpenSSL, Node.js, Python, Docker or external service dependency is introduced.
- All ten existing PSR-4 prefixes remain unchanged. Optimized Composer autoloading
  validates framework and application class paths without PSR-4 errors.
- The `corevia` binary retains its PHP shebang and existing behavior; Git source
  ZIPs now preserve Unix executable permissions.
- Fix the verified minimal-PHP installation diagnostic: `doctor` previously
  reported a mandatory SQLite failure even though the database-free bootstrap
  succeeded. It now checks the JSON web runtime's health and storage, while
  retaining the existing SQLite-demo diagnostic behavior when that driver exists.
- Composer installation has no install/update lifecycle hooks: it does not create
  accounts, contact mail providers or run database migrations. Existing `test` and
  `lint` scripts are still explicitly available.
- Exclude generated private storage while preserving storage placeholders, local
  environment files while preserving root `.env.example`, credentials/signing
  material, machine settings, installed dependencies, caches, private acceptance
  directories and contributor automation.
- Keep test support and the tools it needs because removing them would break the
  distributed `composer test`, `composer lint` and existing CLI behavior. Keep
  optional modules, SDKs, protocol fixtures, starters, examples and public guides.
- Archive tests inject synthetic credentials/runtime files into an isolated
  snapshot. A separate temporary Git index proves that even accidentally tracked
  synthetic private files are filtered by Git export rules. Real secrets are not
  copied or inspected, and the real index is not changed by the test.
- The README's stale statement that current framework/SDK metadata is 3.0.0 is
  corrected. Historical V3 guides, minimum-version compatibility constraints and
  V3 fixtures remain historical. Unchanged JS/Python/Dart SDK versions stay 4.0.0.

## Installation acceptance

The reproducible maintainer check is:

```sh
python tests/v4/composer-project.py
```

Stage only intended framework files first. The check reads the exact index,
creates new private directories beneath ignored `storage/`, and does not commit,
tag, push or publish anything. Use `--php=/absolute/path/to/php` to select a runtime.

For each runtime, both Composer-produced and Git-produced ZIPs must pass:

1. Full supported-resource inventory, exclusion sentinels and Unix CLI mode.
2. Real `composer create-project` using an isolated local package repository,
   clean Composer home/cache and the actual `^4.0` constraint; no registry network
   access or platform-requirement bypass is allowed.
3. `composer validate --strict`, `check-platform-reqs` and optimized
   `dump-autoload --strict-psr`.
4. Composer autoload registration, framework version and application namespace.
5. `about`, `help`, `doctor`, `route:list`, `db:status` and no-INI route listing
   and healthy JSON-runtime diagnostics.
6. Writable storage and new private signing-key creation on first use.
7. Actual `php -n corevia serve` on a dynamic loopback port: welcome, health,
   parameterized route and CSS/JS assets return HTTP 200 without a database.
8. Environment, Composer manifest, private signing key and vendor paths return 404.

The isolated repository proves the install path and constraint resolution locally.
It does not prove public Packagist indexing. A read-only check of the public P2
endpoint returned HTTP 404 during preparation; no registration was attempted.

## Test results

Final results and source-tree fingerprints are recorded in ignored
`storage/v401-preparation/` and per-install `storage/composer-project-*/report.json`.
Initial failed attempts are retained separately rather than represented as
successful evidence.

| Test group | Result |
| --- | --- |
| PHP 8.2.12 | 41/41 check groups passed |
| PHP 8.3.35 | 41/41 check groups passed |
| PHP 8.4.28 | 41/41 check groups passed |
| JavaScript/Python integration groups | 26/26 passed |
| Strengthened synthetic tracked-secret archive/install checks | Both archive paths passed on all three PHP runtimes |
| PHP lint | 414 PHP files passed on each runtime |
| Complete available local V4 acceptance | 33/33 checks passed, with source unchanged |
| Whitespace checks and final candidate review | Passed |

The runtime/integration matrix totals 149 passing check groups, plus the focused
strengthened archive tests. These are process/check-group counts, not individual
assertion counts; `composer test` itself executes the framework's full PHP suite.
There are no outstanding failures in the supported local test plan.

The test plan includes:

- PHP 8.2.12, 8.3.35 and 8.4.28: strict Composer metadata/platform/autoload checks,
  full PHP lint, complete `composer test`, supported no-INI CI entry points,
  additional PHP release/features/hosting/upgrade/example checks and two fresh
  archive installation paths per runtime.
- JavaScript/Python: SDK suites, actual HTTP/CVP/Python/realtime interoperability,
  SMTP and outbound-client protocol fixtures, contracts/profiles, architecture,
  detached packages and staged upgrades, cancellation, concurrency, saved-state
  restoration and historical V3 API/upgrade checks.

PHP 8.4 runs from an isolated official Windows distribution. Its ZIP SHA256
`c1f9280588b7b6ad7b518969022f0b0175f106fab9d0639ad0e4ce7cd47ac1ed`
matches the [official release manifest](https://downloads.php.net/~windows/releases/releases.json).
The laptop's existing PHP installations and system configuration were not changed.

Initial runner corrections: the new test needed valid synthetic Composer auth
JSON and literal local file-wrapper paths for a directory containing spaces. A
sentinel scan initially matched its own test source; the marker is now assembled
without embedding its full value. The isolated PHP 8.4 test configuration needed
ZIP extraction enabled. An overly broad exploratory no-INI run deliberately
removed SQLite from SQL-only suites; the final matrix follows the supported
no-INI entry points in CI. Real-service suites refuse to run without disposable
services and opt-in. None of those exploratory failures is claimed as a pass.
The verified Windows release-fixture cleanup failure was fixed in the test source.
The newly discovered no-SQLite `doctor` failure was reproduced in an installed
archive project, corrected and added to both archive installation paths.

## Compatibility concerns and remaining gates

- No public API, CVP wire format, module composition or runtime data format changes.
- Local Windows runtime tests do not replace Linux CI. GitHub CI on the eventual
  candidate and merged-main commits remains pending because pushing is forbidden
  until approval. Existing 4.0.0 CI results do not validate this candidate.
- The complete available local V4 acceptance suite additionally covers browser
  welcome/workspace and all auth/AI stacks, asset reproducibility, Dart analysis
  and offline/native HTTP contracts, virtual passkeys, optional dependency audit,
  bounded worker endurance and killed-worker recovery. Physical phone testing
  and real identity providers remain separate. The broader V4 `release:check`
  gate still needs actual-commit CI; this report does not claim that combined
  release gate is currently green.
- No production/live site, phone, mail account, real identity provider or cloud
  resource was changed. Real MySQL/PostgreSQL/MongoDB service acceptance and
  optional browser/mobile/cloud/model-provider environments are not rerun for
  this packaging patch. Existing documented V4 validation limits remain in force.
- Public Packagist name/vendor permissions, account registration, GitHub update
  integration, indexing of the approved tag and fresh public-registry installs
  remain publication-time checks. No credentials are needed for local acceptance.
- Root bundled-project installation does not automatically update application-owned
  code in an existing project. Existing staged-upgrade tooling is preserved; use
  the upgrade guide and a separate staging copy.
- Keep `4.0.1 - Unreleased` until approval sets the actual publication date. Tag
  and public release URLs in prepared documentation become available only after
  those publication steps succeed.
- `release:check` requires source-bound evidence and CI on a real release commit;
  it must not be made green using historical or fabricated receipts while this
  candidate is uncommitted.

Proposed notes: [Corevia 4.0.1](release-4.0.1.md). Exact commands, prerequisites and
separate approval boundaries: [publication runbook](publish-4.0.1.md). Composer
metadata/version guidance follows the [official schema](https://getcomposer.org/doc/04-schema.md)
and [Packagist publishing guidance](https://packagist.org/about).
