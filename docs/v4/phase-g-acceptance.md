# Phase G: local acceptance tooling

Phase G has started with an evidence runner. Release acceptance and publication remain incomplete.

Stage intended framework changes first: distribution packaging reads the Git index. Then run from the checkout:

```sh
python tools/v4-acceptance.py --output=storage/v4-acceptance-new --browser --auth-ui --passkeys
```

Use a new ignored output directory each time. PHP/Composer, Python and Node must be available. Install the pinned tools dependencies with `npm ci --ignore-scripts` in `tools/`; install Playwright's Chromium on the development/CI machine or set `COREVIA_BROWSER` to an existing compatible Chrome executable. No frontend tooling is required on the installed application's host.

The runner checks Composer metadata, PHP lint/regression (including new C-F fixtures), independent-process SQLite races, architecture, the V3 public API baseline, backup restoration, detached packages/upgrades and JS/Python SDKs. Browser mode builds/installs another clean indexed application and tests its workspace at three sizes. It does not exercise a maintainer's generated auth files. The JSON receipt records file hashes, a source digest, runtime versions, per-check exit codes and durations; logs stay private under storage. Source drift invalidates the local pass. Inspect the receipt's actual results; this command list is not evidence that a remote CI job ran.

`--auth-ui` verifies reproducible assets and all three auth stacks across JSON/SQLite, MFA/provider fixtures and automated multilingual/theme accessibility checks. `--passkeys` installs and audits the locked optional verifier test dependency and runs actual WebAuthn virtual-authenticator checks. These flags need development browser/tooling, not a production hosting account.

`--services` explicitly opts into disposable MySQL/PostgreSQL/MongoDB fixtures with the separate `COREVIA_TEST_*` settings documented in `tests/support/Services.php`. The service suite includes C data/catalog, D identity/audit/lifecycle/passkey storage, and E/F scheduler/resilience/outbox/usage/sync contracts. It requires all selected SQL drivers plus MongoDB, and fails if an enabled service is unavailable. Run it independently with `COREVIA_DB_TESTS=1 php tests/v4/service-contracts.php` after configuring dedicated service variables. The only permitted test database names are `corevia_ci` and `corevia_test`; application connection secrets are never inherited as test credentials. Do not select production databases. The default run opens no database service or mail/model provider connection.

The receipt always states `release_ready: false` for this development increment and lists unfinished gates. Passing local fixtures cannot complete the unfinished portions of D-F, real service/provider acceptance, security review, long-running worker/load tests, shared/VPS/AWS/Azure platform checks, remote CI or final Composer publication. Docker/real OAuth deferrals from V3 do not automatically count as V4 passes. The package/publication tools remain local; no V4 push/tag/upload is performed.

Use `--ai-ui` for all three AI starter accessibility/streaming modes and `--mobile` for required Dart analysis/unit tests plus native JS/Dart/PHP sync HTTP acceptance. Missing Dart fails an explicitly requested mobile run. The mobile fixtures use private storage and fake tokens; they do not build Android/iOS projects. The service suite now includes knowledge/tool and idempotent sync receipt/API contracts. Actual Qdrant acceptance has its own private version/source-bound receipt; it is not silently substituted by a fake service.

`--worker-endurance --soak-seconds=180` explicitly adds the private lifecycle/memory soak and a real killed-worker lease recovery test. Routine checks keep only the short 1,000-job contract. See [release validation and remaining environments](release-validation.md) for commands, actual target requirements and the distinction between fixture, VPS and cloud/device acceptance.
