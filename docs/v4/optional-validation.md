# Unverified integrations and consumer acceptance

These checks were **not performed for the stated target**. They are documented
deferrals authorized for the V4 source release, not passing results. A local
fixture, generated recipe or interface does not certify that target. Consult
[release validation](release-validation.md) and [the feature review](final-review.md).

| Target not accepted | What to verify before enabling it |
| --- | --- |
| Commercial shared hosting | PHP/extensions and CLI/cron capabilities, rewrite/static routes, private/public separation, HTTPS/session/CSRF, private durable storage, locks and redeploy/rollback. Generate a recipe with `hosting:prepare`; use disposable data and probe public access to private files. |
| AWS/Azure | Disposable account/resource, actual startup/socket/image paths, HTTPS-only ingress, secret injection, persistent/shared storage, health/restart/rollback and bounded workers. Run the generated recipe on that provider; a private VPS is different evidence. |
| Production Docker deployment | CI can validate the image recipe build and basic non-root/driver/kernel/FPM checks; that does not certify an application deployment. Run the optional CI `validate_docker` workflow or build the reviewed recipe locally. Check immutable base images, non-root execution, drivers, FPM health, writable private storage and graceful worker shutdown. Docker is not required for source installation. |
| Real OAuth/OIDC | Register a dedicated test client and callback; verify PKCE/state/replay, consent, linking, recovery, tenant policy and membership revocation. Keep the browser login and credentials with the account owner. No live-provider acceptance is claimed. |
| SMTP provider delivery | Current protocol fixtures and historical V3 live delivery are recorded; no new real email was sent for V4. Verify TLS/auth, approved sender/recipient, queue/retry policy and inbox delivery using your own dedicated account. |
| Collector deployment | Configure your authenticated TLS collector/backend, verify trust/credentials, persistence, partial acceptance, outage bounds and recovery. Loopback Collector interoperability does not certify that deployment. |
| Physical passkeys | Test enrollment/login/recovery, RP ID/origin, resident credentials and user verification with selected platform/security-key authenticators. Virtual WebAuthn tests do not substitute for those devices. |
| Additional mobile targets | Build real Flutter/React Native Android/iOS hosts. Verify durable/encrypted atomic storage, identity changes, offline/reconnect/conflict/deletion, revoked access, OS lifecycle and selected background policy. The accepted synthetic lab is Android 11 arm64/OpenGL only. |
| Assistive technology | Perform screen-reader, keyboard/focus, zoom, reduced-motion, translated/RTL and error-announcement checks across selected browsers and native clients. Automated Axe/keyboard checks are not a complete human accessibility audit. |
| Independent security review | Review the application's authentication, authorization, tenant boundaries, uploads, SSRF, secrets and extension/tool trust. Current internal hardening is not an independent audit or compliance certification. |
| Sustained/production workload | Run representative requests/jobs/streams for the intended duration; measure latency/errors/RSS/native IO, lease expiry, failover, retention and recovery. Bounded synthetic soak numbers are not a capacity or SLO promise. |
| AI model/provider quality | Supply labeled task/retrieval evaluations, embedding dimensions, structured-output failure cases, streaming/cancellation, usage/cost and outage reconciliation. Installed local model streaming is interoperability evidence, not model accuracy. |
| Hostile-code tool isolation | Do not execute untrusted callbacks, generated code or arbitrary commands using the trusted process/callback adapter. Provision and review an actual OS/container isolation boundary before advertising that capability. |
| Public language/package registries | Packagist/npm/PyPI/pub.dev registration is not claimed. Use source/release ZIPs and the explicit static Composer repository. Registry publication requires verified ownership and release provenance. |

## Repeatable framework checks

```sh
python tools/v4-acceptance.py --output=storage/acceptance-new --browser --auth-ui --passkeys --ai-ui --mobile --worker-endurance --soak-seconds=180
python tests/v4/mobile-proxy.py --output=storage/proxy-new
```

Use only disposable MySQL/PostgreSQL/MongoDB accounts for service fixtures; the
allowed database names are `corevia_ci` and `corevia_test`. Follow
`tests/support/Services.php` for `COREVIA_TEST_*` variables, then run
`COREVIA_DB_TESTS=1 php tests/v4/service-contracts.php` in a shell supporting that
environment syntax. Never point acceptance suites at an application's live DB.

For Android, follow [the native lab guide](mobile-device-lab.md). For hosting,
follow [hosting presets](hosting-presets.md) and the generated target instructions.
For real providers, use isolated accounts; do not put secrets in Git or chat.
No test here authorizes sending real email or creating paid cloud resources.

Record exact source/runtime/service versions, results and known limits. Share
only sanitized reports; keep credentials, signing keys, databases and raw logs
outside version control. Change the published support matrix only after actual
target acceptance, not merely after writing a recipe.
