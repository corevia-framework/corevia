# V4 release validation and remaining environments

V4 is still an unpublished development branch. Passing fixtures is evidence for
their stated scope; it does not certify every hosting provider, identity provider,
mobile device or production workload. V3's optional OAuth/Docker deferrals do not
automatically apply to V4. Keep the stable version and release metadata unchanged
until the selected V4 release gates have passed or the maintainer explicitly
changes the advertised scope.

## Repeatable build-machine checks

Stage only intended framework files, preserving generated application files.
Use a new ignored output directory for every receipt:

```sh
python tools/v4-acceptance.py --output=storage/v4-final-new --browser --auth-ui --passkeys --ai-ui --mobile
python tools/v4-acceptance.py --output=storage/v4-endurance-new --worker-endurance --soak-seconds=180
```

These commands require the documented development runtimes, pinned browser/tools
dependencies and Dart for mobile mode. They do not contact real mail/model/OAuth
providers. The source-bound report must show both `local_passed` and
`source_unchanged`; `release_ready` deliberately remains false until the external
and release gates are resolved. See [acceptance tooling](phase-g-acceptance.md).

The endurance mode runs real queued job scopes, bounded failure injection, weak
reference cleanup and PHP allocated-memory growth after 1,000 warmup jobs. Linux
also samples its own process RSS; Windows reports that measurement as unavailable,
not zero. Test-only completed/dead rows are recycled so the measurement does not
mistake retained fixture data for a scope leak. This is not the database retention
policy for an application. The second fixture kills its own PHP worker after a
simulated idempotent effect, waits for real SQLite lease expiration, then verifies
replacement ownership, cleanup, stable effect identity and stale-ack rejection.
Hard-killed code does not run cleanup. No live database or external effect is used.

The fixture permits 10-1,800 seconds. It is a bounded development test, not a
production SLO, multi-host benchmark, native allocator proof on every OS or a
substitute for hours of the intended site's actual workload. Never compare its
in-memory numbers directly with Laravel/CodeIgniter HTTP/database benchmarks.

## Private Linux/VPS checks

Use a disposable source copy, private credentials and loopback ports. The current
authorized host uses only `/var/www/corevia/.acceptance`. Unrelated live site
directories are excluded from inspection, copying, configuration and tests.

```sh
python3 tests/v4/worker-endurance.py --output=storage/endurance-new --seconds=180
python3 tests/v4/cancel-http.py
python3 tests/v4/hosting-linux.py --recipes=packages/cli/resources/hosting --output-root=storage --application=/var/www/corevia/.acceptance/sources/DISPOSABLE_COPY
```

The hosting fixture substitutes private paths and listeners, loads its own nginx
and FPM configs, and stops only its own processes. Full-app mode checks an isolated
framework welcome/hello/static/404 surface in addition to the synthetic recipe
boundary tests. It is not a public TLS deployment, real application's business
upgrade or cloud-platform pass. Source hashes accompany the receipt.

Run the separately documented SQL/MongoDB and Qdrant contracts only against
explicit disposable services and restricted test accounts. Run the actual
[Collector fixture](observability-and-budgets.md#actual-collector-fixture) with a
checksum-verified binary. Reconcile each report's exact source bytes; do not apply
an old receipt automatically to changed code. Keep private credentials, databases,
logs, binaries and reports in ignored acceptance storage.

## Checks that need their actual targets

| Target | Concrete acceptance | Status before that target is available |
| --- | --- | --- |
| Constrained shared hosting | Off-server-built public_html/private upload, PHP/web and CLI capabilities, rewrite/static/private probes, HTTPS/session/CSRF, cron/locks/log rotation and persistence across redeploy | Local Apache fixture only; provider unverified |
| AWS | Explicit disposable EC2 or Elastic Beanstalk PHP branch, reviewed recipe, HTTPS-only edge/origin access, private persistent/shared storage, required drivers, install/redeploy/rollback and bounded jobs | Recipes emitted; no cloud resource provisioned |
| Azure | Disposable Linux App Service or VM, actual image/socket/startup behavior, ZIP root layout, HTTPS-only policy, private persistent storage, restart/redeploy/rollback and selected shared adapters | nginx/FPM recipe fixture only; App Service unverified |
| Docker | Reviewed immutable PHP/Composer image digests, isolated build from intended source, non-root FPM/driver checks, private storage/keys, public boundary and queue lifecycle | Recipe/configured optional CI job; no daemon available on the development laptop |
| Identity providers | Dedicated OIDC provider/client/callback/account, PKCE/state/replay, linking/MFA/tenant policy, current membership/revocation, failure recovery and redacted logs | Provider-neutral fixtures and virtual WebAuthn pass; real provider/physical authenticator unverified |
| AI providers/tools | Intended model and embeddings/dimensions, representative labeled retrieval/evaluation cases, structured errors, usage/cancellation/uncertain billing; actual sandbox review before any untrusted code | SQL/Qdrant/contract/process fixtures pass; no paid inference or untrusted-code sandbox certified |
| Native mobile | Real Android/iOS Flutter and React Native host builds, approved durable atomic CAS storage, device restart/offline/conflict/revocation/tombstones, accessibility and any selected background policy | Physical Android 11 OpenGL lab passes filesystem, HTTPS/auth-loss/tenant, two standalone restarts without the PC backend and basic visible-UI checks. Evidence is split across the retained full-run failure and focused UI completion. Default renderer, React Native/iOS, production adapters and manual/background acceptance remain unverified. See [recorded scope](progress.md#android-phone-acceptance-2026-11-15). |
| Security/accessibility/support | Independent scoped review, actual assistive technology/keyboard and device checks, vulnerability disposition, intended workload/load/RSS/native-I/O results and final supported runtime matrix | Automated browser/Axe/runtime contracts and internal hardening are evidence, not independent certification |
| GitHub/public packages | Required CI on the exact intended commit, final version/changelog/upgrade review, release artifacts and package checksums, Composer publication and final main/tag/release | Kept local until the maintainer's release conditions are met |

The VPS laptop can provide more Linux acceptance without creating AWS/Azure or
native mobile environments. No previous real SMTP delivery authorizes another
email; use dedicated accounts and explicitly approved recipients/messages for any
new real delivery check. Real OAuth sign-in stays in the account owner's browser.
Do not paste secrets into chat or publish acceptance files.

Maintainer update (2026-11-09): application-specific model quality and external AI-provider acceptance are delegated to framework users, with [installed local-model interoperability fixtures](local-models-and-mobile.md) supplied as a reference. They no longer require maintainer paid-model credentials before V4 release. This does not waive framework authorization, budget, contract, data-isolation, native-mobile or identity-provider checks. Selected Android 11 OpenGL lab acceptance subsequently passed on 2026-11-15; additional native targets and operating policies remain pending as recorded above.
