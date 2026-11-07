# V4 compatibility and ownership baseline

Baseline: V3 source commit `d7429ed14356de39aaf8e98fa0993fc5c8f38337` on the local V3 history. The first V4 work is additive. Framework/SDK version metadata remains 3.0.0 until an intentional versioning/release step; the detached experimental Composer dependency is labeled `dev-v4`, not a stable release.

## Ownership

| Owner | Paths and responsibilities |
| --- | --- |
| Application | `app/`, `routes/`, `config/`, `resources/`, `public/`, application Composer metadata/lock and deployment policy. Auth/AI files become application-owned when generated. |
| Framework | PHP packages, starter templates and bundled assets, protocol/SDK implementation and framework CLI. In the detached prototype these live under `vendor/corevia-framework/runtime/`. |
| Operator | Secrets, private storage, signing/encryption keys, databases, services, backups, process supervision and hosting permissions. None belong in a distribution artifact. |
| Development tooling | Tests, CI, architecture checks, build tools and acceptance receipts. These are not installed into the application skeleton. |

Do not replace an application's files when updating a dependency. Keep legacy checkout bootstraps operational while experimenting with detached dependencies. There is no automatic migration of an existing V3 application in this increment.

## Compatibility contracts to preserve

- Existing `Corevia::create()`, `modules()`, canonical `compose()`, runtime profiles, execution scopes, flow persistence/resume and shutdown behavior.
- Browser session/CSRF/cookie and account-store behavior; starters remain additive and reject existing output files.
- CVP/1 and CVP/2 wire versions independently of framework/package version numbers; generated PHP/JS/TS/Python/Dart contracts remain supported.
- Existing persisted workflow/auth formats and explicit schema setup; no silent driver conversion or arbitrary schema downgrade promise.
- Private source-bound HMAC application artifacts: detached framework dependencies and executable Composer autoload files must participate in the declared source fingerprint.
- A PHP/JSON-only database-free application path. Composer/build tools can run off-server. Optional Python, Node, databases and infrastructure remain capability-specific.

This is an initial critical-contract inventory, not an exhaustive public-symbol compatibility snapshot. Full API/schema inventory, hosting measurements, threat models and benchmark baselines remain Phase A work.

## First design decisions

1. Use one experimental aggregate runtime package before attempting independent releases of every internal PHP package. Existing internal namespace boundaries still apply; more packages require real dependency/versioning tests.
2. Discover framework-owned templates from the executing framework's location, independently of the application's root and current working directory.
3. Build artifacts from an explicit allowlist of Git-index blobs. Untracked local applications, keys, acceptance fixtures, generated assets and storage must not enter a clean skeleton. Indexed changes must be staged before a build; unstaged edits are intentionally excluded.
4. Produce deterministic content manifests and ZIPs without running application code, dependency installers or third-party hooks. A digest detects differences; it is not publisher authentication or a signed software supply chain.
5. Refuse existing output directories and constrain prototype output to ignored repository `storage/`. Build tools do not deploy, migrate or update running applications.
6. Treat shared/VPS/AWS/Azure recipes as planned until target-specific acceptance is available. A detached dependency prototype is not evidence of universal hosting compatibility.
