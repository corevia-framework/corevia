# Identity, account management, delivery and authorization: local V3 phases

These four workstreams extend the existing auth starters. V3 remains local and unreleased. They do not install a starter into an existing application or overwrite previously generated application files.

## Try it without a database

In a fresh V3 application:

```sh
php corevia starter:auth --stack=bootstrap
php corevia serve --port=8081
```

Use `tailwind` or `react` for the other kits. Set `APP_URL` to the origin you actually serve. Open `/register`, create an account, then use **Account settings** on the dashboard. The default is JSON storage and a private development mailbox. No MySQL server, npm build or external service is needed to try it. OpenSSL is needed for MFA, provider login and encrypted queued mail; base authentication/account/authorization still run without it. An account that already has MFA cannot log in with password alone if OpenSSL/MFA support becomes unavailable.

All kits share a server-rendered `/account` settings page and small same-origin script. React continues to render login, registration, dashboard and recovery flows. The settings forms have explicit POST actions, including when JavaScript is disabled. JavaScript provides inline messages, one-time secret/code display and navigation after changes; the no-JavaScript settings fallback returns a JSON response.

## Stronger authentication

On `/account`, start authenticator setup with your current password. Add the displayed Base32 secret or `otpauth://` URI to an authenticator app, then confirm its six-digit code and your current password. A QR image is not generated. Save the eight recovery codes privately: each has 80 random bits, is stored only as a SHA-256 digest and can be used once. Codes are shown only in the successful enrollment response. To replace the recovery set, disable and enroll a new authenticator after reauthentication.

TOTP uses HMAC-SHA1, six digits and a 30-second period, allowing one neighboring step in each direction. Used counters cannot be replayed. Enrollment expires after ten minutes and is bound to the account version and browser session. Active secrets and pending enrollment secrets use AES-256-GCM with purpose/account binding. RFC [6238](https://www.rfc-editor.org/rfc/rfc6238) test vectors cover the underlying algorithm. Do not send setup URIs to a third-party QR service.

The login form accepts an authenticator **or** recovery code. MFA is verified before creating an authenticated session. Enabling/disabling MFA revokes existing sessions/tokens atomically. Password recovery preserves MFA. Failed factor attempts have a persistent per-account limit.

Remember me creates a session with a fixed maximum of 30 days; ordinary sessions retain their configured shorter lifetime. It does not slide indefinitely. Credential/role changes and explicit revocation still invalidate it. Sensitive account actions require the current password and another factor when enabled, even in a remembered session.

## Provider-neutral OAuth

Register provider adapter class names in the generated `config/auth.php`:

```php
'oauth' => ['company' => \App\Auth\CompanyProvider::class],
```

The container resolves each class, which must implement `Corevia\Auth\OAuthProvider`:

```php
public function authorizationEndpoint(): string;
public function clientId(): string;
public function scopes(): array;
public function identity(string $code, string $verifier, string $redirectUri): string;
```

`identity()` exchanges the authorization code using the supplied PKCE verifier and exact redirect URI, then returns the provider's stable subject identifier. Custom adapters own transport/validation. The [follow-on workstreams](remaining-phases.md) also provide GitHub and Google/pinned RS256 OIDC adapters using a bounded verified HTTPS client. OIDC adapters implement `OidcProvider` and receive the stored flow nonce. Require S256 PKCE support at the provider. Provider credentials stay server-side.

Corevia builds authorization-code requests with random session-bound, single-use state and S256 PKCE. Verifiers are encrypted in private expiring state. Each provider has a distinct callback `/auth/oauth/{provider}/callback`, preventing callbacks from being swapped between configured providers. The trusted `APP_URL` supplies redirect origins. Corevia follows the session/state/PKCE and distinct-callback approach described in OAuth security [BCP 240](https://www.rfc-editor.org/rfc/rfc9700.html).

First sign in with a password and **Link** the provider in account settings. Linking requires the current password and MFA when enabled. Provider subjects are uniquely bound to an existing account under the same store transaction that updates credentials and revokes sessions. Corevia does not automatically create accounts or link by matching an email claim. Thereafter, **Continue with provider** signs in that linked account. MFA accounts receive a five-minute additional-factor challenge before an authenticated session exists. A pending factor challenge permits only one in-flight completion. Unlinking revokes sessions and outstanding account tokens.

Start requests use POST plus CSRF. JavaScript navigates to the returned authorization URL; the PHP no-JavaScript fallback displays an explicit provider link. This works with `form-action 'self'`. Callback responses have no-store and no-referrer policy. Configure reverse-proxy/access-log redaction for OAuth `code` and `state` query parameters; providers necessarily return the authorization code in the callback query. Access/refresh tokens are not accepted from browsers or persisted by the framework integration.

## Account management and persistence

Settings support name, email and password changes, session listing/revocation and account deletion. All mutation forms require password confirmation and active MFA. Email changes clear verified status and request verification when delivery is enabled. Account deletion removes framework account/security/session/token/provider-binding data; application domain resources need an application deletion policy.

`IdentityStore` extends `AccountStore` with atomic identity/security state changes, provider bindings, safe session enumeration and owner-constrained revocation. Bundled JSON, SQL and the subsequent CAS/MongoDB adapter implement it. SQL adds an indexed state table; run `php corevia auth:migrate` when upgrading a generated SQL app. Existing user/session tables remain compatible. Driver changes do not automatically migrate accounts. MongoDB auth requires the atomic CAS backend, not the generic document-store interface; see [follow-on adapters](remaining-phases.md).

Identity callbacks execute under the adapter's exclusive lock/transaction. Credential versions compare atomically; email uniqueness, MFA credential updates, session/token revocation and account deletion commit together. Role, tenant and provider changes also force credential-version/session invalidation. Generic transient-state writes reject reserved `user:`/`oid:` namespaces. A failed callback rolls back. Do not recursively call an adapter from a mutation callback. SQL auth owns its transactions and requires a dedicated connection when business code holds transactions. External edits must honor these version/revocation rules.

Session responses contain an irreversible display/revocation handle, expiry and whether it is current. They do not disclose cookie values, stored session digests or CSRF secrets from other sessions. Revocation checks ownership. Profile endpoints whitelist their fields; role/permission fields cannot be mass assigned.

## Production email

Development preview delivery remains the default outside production. Configure the process environment for queued SMTP:

```text
APP_ENV=production
APP_URL=https://your-app.example
APP_HTTPS=true
AUTH_DELIVERY=smtp
SMTP_HOST=your-mail-provider.example
SMTP_PORT=587
SMTP_ENCRYPTION=starttls
SMTP_USERNAME=your-provider-user
SMTP_PASSWORD=your-provider-secret
MAIL_FROM=accounts@your-app.example
```

Use `smtps` with the appropriate provider port (commonly 465) for implicit TLS. Certificate-chain and hostname verification are mandatory. `SMTP_CA_FILE` can specify a trusted CA bundle; it does not disable verification. STARTTLS must be advertised and succeed before authentication. SMTP PLAIN/LOGIN are supported over TLS only. Credentials and server transcripts are excluded from framework transport errors.

```sh
php corevia mail:status
php corevia mail:work --once
php corevia mail:work --max=100
```

Run the bounded worker repeatedly under your process supervisor/scheduler. Each claimed message runs within a `mail.job` execution scope, with cleanup on failure/success. A 120-second lease prevents concurrent workers from sending the same active job; crashed leases can be retried. SMTP operations have a 30-second overall budget. Retries use exponential backoff, stop after six failures and never exceed the underlying link's expiry. Queue output contains status and opaque job IDs only. SMTP acceptance is **at least once**: a lost acknowledgement or crash after acceptance can cause a duplicate; stable Message-ID values help downstream deduplication but do not guarantee it.

The default spool is a bounded private single-host file, with at most 1,000 messages/8 MiB. Payloads, including recipients and bearer links, are encrypted with AES-256-GCM. Corrupt files are preserved and rejected. Counts/status do not decrypt messages. This is not a distributed broker. Domain verification, SPF/DKIM/DMARC, provider quotas and bounce handling remain deployment/provider responsibilities; real external delivery has not been exercised by the local tests.

Preserve and back up `storage/auth/security.key` together with encrypted records/spools. Never commit it, publish it, rotate/delete it independently or regenerate it to recover existing ciphertext. Changing the encryption key without a migration makes existing MFA secrets and queued messages unreadable. Store the entire storage directory outside the public document root and apply private filesystem permissions/Windows ACLs. Multi-host deployments need shared key provisioning and an appropriate delivery backend; copying this file spool across hosts is not supported.

## Authorization

Roles are explicit application configuration mapping names to exact abilities. There is no wildcard or implicit superuser. Registration/settings cannot grant roles. Bootstrap an operator-selected account locally:

```sh
php corevia auth:roles --email=owner@example.test --set=admin
php corevia auth:roles --email=editor@example.test --set=editor --tenant=team-a
php corevia auth:roles --email=owner@example.test --set=
```

`--set` replaces roles in the selected global/tenant scope; an empty set revokes them. Grants/revocations invalidate all account sessions. Sign in again after a role change. `/auth/admin` demonstrates `admin.access`; it denies access by default.

```php
use Corevia\Auth\{Authorize, ResourceIdentity, SessionMiddleware};

$router->get('/documents/{id}', $controller)
    ->middleware(new SessionMiddleware($auth))
    ->middleware(new Authorize($gate, 'documents.read', function ($request) use ($documents) {
        $document = $documents->find($request->params['id']);
        return $document === null ? null : new ResourceIdentity($document->tenantId, $document->ownerId);
    }, owned: true));
```

Load resource tenant/owner identity on the server. Do not construct it from request body owner/tenant claims. Missing resources deny access. Tenant resources use only roles assigned in that tenant; global admin roles do not bypass membership. With `owned: true`, ownership is an additional requirement. An ability, membership and required ownership must all match. Session validity and current assignments are checked on use.

## Validation and remaining scope

Run `php corevia test`, `node tests/v3/smtp.mjs` and the browser/rebuild commands in [auth starters](auth-starters.md). Browser tests cover extension-free JSON, encrypted JSON (`COREVIA_AUTH_CRYPTO=1`) and SQLite (`COREVIA_AUTH_SQL=1`). The provider page is intercepted in memory; SMTP uses loopback TLS fixtures. No external credentials or email are needed. Live MySQL/PostgreSQL identity checks are wired into CI but remain unrun locally; GitHub CI has not run for this unpublished work.

The [follow-on workstreams](remaining-phases.md) implement CAS/MongoDB auth, provider clients, shared jobs/mail and AI/deployment tooling. WebAuthn/passkeys and organization invitations/billing remain optional future additions. Real target-service acceptance and plan/security/upgrade reviews remain release requirements. These milestones do not release all of V3.
