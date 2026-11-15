# V4 identity, audit and account-data APIs

These are local V4 additions. They are optional and do not enable an external provider, send mail, or modify existing generated application files. Final release acceptance remains open.

## Organization SSO

`OrganizationSso` is an `OAuthLoginPolicy` for the existing provider-neutral OAuth service. Configure named, nonce-verifying `OidcProvider` instances and an explicit tenant/role policy. A matching email domain never enrolls a user, links an account or grants a role. An administrator must provision organization membership; initial linking still requires the current password and any configured MFA.

```php
$policy = new Corevia\Auth\OrganizationSso($identityStore, [
    'company' => ['tenant' => 'acme', 'roles' => ['member'], 'max_age' => 3600],
]);
$oauth = new Corevia\Auth\OAuthService(
    $identityStore, $secretBox, $auth, ['company' => $configuredOidcProvider], $policy,
);
```

`RequireOrganizationSso($policy, 'acme')` runs after session middleware on routes requiring fresh organization sign-in. A password-only login or user API credential does not acquire this assurance. The policy rechecks membership, credential version, session ownership and age. MFA-enabled accounts receive assurance only after successful second-factor completion. Restrict business actions with the application's normal authorization policy as well.

Fresh auth starters accept `organization_sso` policies in their application-owned config, expose the policy in the container and reject missing/non-OIDC providers. Existing custom `OAuthService` callers retain their four-argument constructor behavior; the fifth policy argument is optional. In-flight V3 MFA provider challenges without provider metadata must restart if a new organization policy is enabled.

## Service accounts

`ServiceAccounts($identityStore, ['jobs.submit', 'records.read'])` declares the maximum machine permissions. Resolve an operator tenant scope granting `service.manage` for creation/rotation/revocation and `service.read` for descriptions. `TenantAccess::resolveMany()` can resolve several explicit capabilities, rechecking all of them against current authority.

```php
$created = $machines->create($operatorScope, 'Importer', ['jobs.submit']);
// Save the returned credential privately: it is returned only at creation/rotation.
$machineScope = $machines->authenticate($credential, 'acme', 'jobs.submit');
```

Credentials have a separate `cvs4_` format, are digest-stored, tenant-bound, quota-limited and expire after at most 90 days. Rotation/revocation invalidates already-resolved scopes on their next operation. Machine scopes cannot manage machine credentials, mint browser sessions or enter the human API-token flow. `ServiceTokenMiddleware` enforces explicit tenant/operation requirements and HTTPS in production. Descriptions expose neither digests nor raw credentials.

Service accounts belong to the organization, not automatically to their creating human account. The application's organization and data-retention policies decide what to do with shared resources during human-account erasure. Keep provisioning endpoints behind administrator authorization and record their audit events; no public provisioning route is installed by default.

## Optional passkeys

The `WebAuthnVerifier` bridge uses [web-auth/webauthn-lib](https://github.com/web-auth/webauthn-framework), tested locally at **5.3.12**. The bridge accepts patched 5.3 releases from 5.3.12; other minor branches require validation before changing its support guard. Composer advisory blocking remains enabled. The isolated test dependency is locked under `tests/v4/passkey-dependencies/`; it is not a mandatory framework runtime dependency.

For an application choosing this feature, install its optional dependency on the build machine:

```sh
composer require 'web-auth/webauthn-lib:~5.3.12'
```

Then set `AUTH_PASSKEYS=true` in private application configuration. The generated routes create `Passkeys` and mount registration/login/list/removal APIs only when explicitly enabled. Custom identity adapters must implement the optional `PasskeyStore` capability. JSON, SQLite and the NoSQL snapshot implementation support atomic ownership and credential-state compare/update; real SQL/MongoDB service acceptance is still required for the new paths.

The bridge requires exact HTTPS origins, resident credentials, user presence and user verification. HTTP is allowed only on development loopback. Subdomain wildcards, conditional-create verification overrides and enterprise attestation are not enabled. Registration requires the current password/MFA, binds a one-use five-minute challenge to the current session/version, and limits each account to ten keys. Usernameless login avoids exposing an email-based credential list. Existing account MFA still applies after the passkey assertion; passkeys do not grant organization SSO assurance. Enrollment/removal revoke previous sessions; assertions compare/update stored credential counters atomically.

Credential private keys stay with the authenticator. The server stores public records, labels and counters. Recovery uses existing password/MFA recovery policies; review and remove compromised passkeys explicitly. Password reset revokes sessions and tokens, but does not silently remove all enrolled authenticators. Account deletion removes owned global credential bindings as well as account records.

Tests use Chrome's isolated virtual authenticator and the real verifier, including signed negative assertions for missing UV, wrong RP and stale counters, origin/port mismatch, replay, bad signatures and user handles. These tests do not establish physical-device or every-browser interoperability. The verifier's own logging remains disabled through its default null logger; do not enable verbose credential/header logging in production.

## Audit integrity and retention

`AuditTrail` uses transactional SQL tables, declared non-content event schemas, pseudonymous actor references and an HMAC chain. It requires explicit `audit.append`, `audit.read`, `audit.retain` and `audit.prune` tenant capabilities. Configure independent private key IDs and retain old verification keys during rotation. Metadata accepts only declared bounded identifiers, integers and booleans; never use it for passwords, tokens, addresses, prompts or arbitrary request payloads.

```php
$audit = new Corevia\Persistence\AuditTrail($auditSql, $privateKeyRing, 'current', [
    'record.changed' => ['record_id' => 'identifier', 'revision' => 'integer'],
]);
$audit->migrate(); // operator setup, not request boot
$audit->append($writerScope, 'record.changed', ['record_id' => $id, 'revision' => 2]);
$checkpoint = $audit->checkpoint($readerScope);
```

Use a shared authoritative clock and transactional tables. Appending on the same SQL connection inside an application transaction rolls back with its application write. `verify` detects modified events, chain gaps and mismatched heads. Optional stable event IDs deduplicate retries and reject changed content. Verification is paged; retained capacity is bounded at 100,000 events per tenant stream.

HMAC verification is not non-repudiation or protection against loss of both database and keys. Keep signed checkpoints **outside the audit database** to detect restoration/truncation to an older valid snapshot. Take a checkpoint first, export the complete segment through that sequence, verify its final MAC against the checkpoint and durably archive both before confirming pruning. `prune` requires explicit archive confirmation, respects the configured minimum retention, removes at most 500 records per call and preserves signed chain continuation. `hold` prevents pruning. Earlier pruned segments require their archive for historical verification. This API does not certify external archive durability or legal compliance.

## Application-owned export and erasure

Implement `AccountDataAdapter` for each owned data source, with a stable name/revision, bounded export, retention preflight and idempotent erase. Do not scan arbitrary tables/directories or infer ownership from a field name. Application resources shared with other users need an explicit ownership/retention policy. `erase` must recheck relevant holds at its own storage commit and use the supplied stable operation ID for deduplication.

`AccountLifecycle($store, $auth, $adapters, $independentReceiptKey)` adds password/MFA-confirmed exports and durable erasure coordination. Exports whitelist public account fields and registered adapter output; the built-in identity portion excludes password hashes, MFA secrets, session credentials and provider bindings. Application adapters must explicitly filter sensitive application fields. Each adapter is capped at 1 MiB and the combined application data at 8 MiB; large applications need a dedicated bounded archive job. Multi-store export is not an automatic consistent database snapshot.

`requestDeletion($request, $context)` requires `confirmation=DELETE`. It disables new account sessions/recovery issuance and revokes active sessions/tokens before application erasure. The workflow persists its adapter plan and progress, checks all retention preflights, fences workers and retains uncertain failures for operator reconciliation. `work($userId, $operationId)` performs bounded adapter steps, then deletes the authentication identity. Retrying unfinished steps uses the same IDs. It never treats a missing identity alone as proof that application data was erased. Plan/revision drift requires operator review. Receipts use a pseudonymous subject reference; completed/cancelled receipts expire after configured retention, while uncertain work does not disappear automatically.

An optional audit callback receives an event, declared metadata and stable event ID. It must deduplicate by that ID; an `AuditTrail` sink can do so. An audit failure blocks further erasure progress and can be retried. Do not perform external side effects inside identity-store callbacks.

`pending`, `status`, `work` and `cancel` are **operator/background APIs**, not unauthenticated routes. Cancellation is allowed only before irreversible work and while no worker owns an active lease. Once erasure starts, recovery requires the appropriate matching backups or application reconciliation. Backup retention, legal holds and external provider data are governed by explicitly registered policies; no blanket deletion/compliance guarantee is made.

Bind a configured `AccountLifecycle` factory in the application container and set the starter's `lifecycle_service` to that binding. The optional export route uses POST, CSRF and fresh credentials; account deletion returns an accepted operation reference rather than claiming background work already finished. With no lifecycle service, existing V3 identity-only deletion behavior is retained and clearly described in the generated view. The `lifecycle_deletion` identity-security field and `pkey:` credential namespace are reserved by these new features.

## Validation commands and open acceptance

```sh
php tests/v4/audit.php
php tests/v4/service-accounts.php
php tests/v4/account-lifecycle.php
php tests/v4/organization-sso.php
php tests/v4/passkey-storage.php
composer install --working-dir=tests/v4/passkey-dependencies --no-interaction --no-scripts --no-plugins
node tests/v4/passkey-browser.mjs
```

Local backend contracts pass on JSON/SQLite, with passkey ownership/CAS tests also covering a NoSQL fixture. Full PHP regression and the real V3 API baseline pass. All three auth stacks pass the database-free and SQLite/MFA/provider browser workflows; automated multilingual/theme accessibility checks pass, with the native React account journey also checked. real identity providers, physical devices, real-service drivers, security review and final release gates remain explicit outstanding work.
