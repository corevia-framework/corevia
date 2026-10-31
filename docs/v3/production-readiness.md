# V3 production acceptance

V3 is prepared for publication on the release branch with framework/SDK version 3.0.0. A stable tag/release requires GitHub CI acceptance. Target-runtime acceptance does not silently close the full framework release gates.

## Measured scope

The production review tightened auth origins and deployment policy. Controls/backslashes, malformed HTTPS/AI endpoints, public signing/auth files, unsafe Unix permissions and SQLite multi-host claims now fail checks. Custom delivery adapters remain supported; `app:verify` validates their runtime wiring. Deployment inspection remains offline and reports its limits.

Real MySQL, PostgreSQL and replica-set MongoDB contracts were executed in Linux staging. They cover persistence, account tokens, identity/security state, provider bindings, queue ownership/retries/fencing and encrypted shared mail. Additional PostgreSQL/MongoDB binaries and PHP drivers were extracted privately; no system packages or live services were replaced. MySQL uses an explicitly supplied disposable test database. The MongoDB test replica set has one node; database failover is not established by this test.

Two independent production app pools passed private TLS nginx/PHP-FPM tests with CA verification, signed boot, secure cookies, shared session revocation, private-file denial and a concurrent shared worker claim. The live nginx/FPM configuration and existing sites were not restarted or reconfigured. This establishes application coordination across separate processes, not physical-host failover.

The implementation review inspected credential/version mutations, account-token single use, purpose-bound MFA encryption/replay counters, OAuth state/PKCE/nonce/pinned RS256 keys, role/tenant authority, bounded HTTP/SMTP transport, worker ownership/cleanup, signed boot and upgrade boundaries. Concrete findings were fixed and regression-tested. This is not independent penetration testing or certification.

V1/V2 fixtures, the learning app, browser/security/transport/SDK/architecture tests and actual cached-model inference have additional private evidence. Re-run matching verification after source changes. Prior results do not certify different source/configuration. JSON auth stays format 1; driver changes do not import accounts. Generated application config/routes are not overwritten. See [upgrade notes](upgrade.md).

## Remaining release gates

| Gate | Required acceptance |
| --- | --- |
| 14-point scope/security/upgrade review | Completed for the maintainer-confirmed scope in [final review](final-review.md), including an actual V2-tag persisted-data/backup rollback fixture. This is a framework implementation review, not independent certification. |
| External SMTP | Verified STARTTLS/authentication/relay acceptance and explicit Inbox confirmation for one approved synthetic email. Production delivery still defaults to disabled until an application configures it. |
| External OAuth/OIDC | Explicitly deferred by the maintainer. Fixture verification remains; users must validate their configured provider before enabling it. [Self-test guide](optional-validation.md). |
| Docker image | Explicitly deferred; the recipe is optional/unverified and the container CI job is opt-in. [Self-test guide](optional-validation.md). |
| GitHub CI | Required on the published commit before a stable tag/release. Local/Linux staging success does not synthesize GitHub CI success. See the repository Actions run for that exact commit. |
| Existing sites | Explicitly outside this task. No inspection, copying, changes or upgrade tests are authorized for the excluded live sites. Application owners remain responsible for their own rollout/backup policy. |
| Scale/failover/AI operations | Physical-host/database failover, production load, real embeddings and native/GPU preemption require the selected deployment/model and operational policy. |

Private source-bound staging receipts live in `storage/v3-staging-verification.json`; local verification remains in `storage/v3-verification.json`. `release:check` combines matching receipts without overwriting service acceptance when local fixtures are rerun and lists explicit optional deferrals separately from passed checks. These operator-supplied records are not independent certification. Credentials, SSH keys and logs remain ignored and excluded from Docker contexts. No acceptance command publishes or changes the framework version.
