# V3 production acceptance

V3 remains local and unreleased. Target-runtime acceptance does not silently close the full framework release gates.

## Measured scope

The production review tightened auth origins and deployment policy. Controls/backslashes, malformed HTTPS/AI endpoints, public signing/auth files, unsafe Unix permissions and SQLite multi-host claims now fail checks. Custom delivery adapters remain supported; `app:verify` validates their runtime wiring. Deployment inspection remains offline and reports its limits.

Real MySQL, PostgreSQL and replica-set MongoDB contracts were executed in Linux staging. They cover persistence, account tokens, identity/security state, provider bindings, queue ownership/retries/fencing and encrypted shared mail. Additional PostgreSQL/MongoDB binaries and PHP drivers were extracted privately; no system packages or live services were replaced. MySQL uses an explicitly supplied disposable test database. The MongoDB test replica set has one node; database failover is not established by this test.

Two independent production app pools passed private TLS nginx/PHP-FPM tests with CA verification, signed boot, secure cookies, shared session revocation, private-file denial and a concurrent shared worker claim. The live nginx/FPM configuration and existing sites were not restarted or reconfigured. This establishes application coordination across separate processes, not physical-host failover.

The implementation review inspected credential/version mutations, account-token single use, purpose-bound MFA encryption/replay counters, OAuth state/PKCE/nonce/pinned RS256 keys, role/tenant authority, bounded HTTP/SMTP transport, worker ownership/cleanup, signed boot and upgrade boundaries. Concrete findings were fixed and regression-tested. This is not independent penetration testing or certification.

V1/V2 fixtures, the learning app, browser/security/transport/SDK/architecture tests and actual cached-model inference have additional private evidence. Re-run matching verification after source changes. Prior results do not certify different source/configuration. JSON auth stays format 1; driver changes do not import accounts. Generated application config/routes are not overwritten. See [upgrade notes](upgrade.md).

## Remaining release gates

| Gate | Required acceptance |
| --- | --- |
| Original 14-point scope | Reconcile the complete agreed list; points 9–14 are documented, but completion of unspecified requirements must not be invented. |
| Security/upgrade sign-off | Review final code and application-specific deployment, data backup and rollback risks. Source inspection and fixtures are not independent certification. |
| External SMTP | No provider account is currently available. TLS fixtures/shared mail contracts pass; external delivery is unverified. Production delivery defaults to disabled, omitting recovery/verification routes and UI. Test a real adapter before enabling them. |
| External OAuth/OIDC | Actual client credentials, callback registration and user login/link/replay acceptance. Signed-token/PKCE fixtures do not establish live provider acceptance. |
| Containers/GitHub CI | Definitions exist; bare-metal staging does not test the image or run GitHub CI. V3 has not been pushed. |
| Existing sites | Validate each site's staged code/data/key backup, application behavior and rollback. Existing sites are not upgraded automatically. |
| Scale/failover/AI operations | Physical-host/database failover, production load, real embeddings and native/GPU preemption require the selected deployment/model and operational policy. |

Private source-bound staging receipts live in `storage/v3-staging-verification.json`; local verification remains in `storage/v3-verification.json`. `release:check` combines matching receipts without overwriting service acceptance when local fixtures are rerun. These operator-supplied records are not independent certification. Credentials, SSH keys and logs remain ignored and excluded from Docker contexts. No acceptance command publishes or changes the framework version.
