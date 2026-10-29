# Auth storage, password recovery and email verification

These are local V3 additions. V3 remains unreleased and unpushed. Fresh `starter:auth` kits include the new configuration and pages in Bootstrap, Tailwind and React. Existing generated apps are not overwritten: merge the new `config/auth.php`, `routes/auth.php`, account view/frontend sources and rebuilt assets deliberately. Upgrading framework classes alone preserves base auth behavior but does not enable new routes/delivery in an older kit.

## SQL auth without changing the rest of your app

The default JSON starter still runs with `php -n`. To use SQL for users, sessions, limits and account tokens:

```powershell
$env:AUTH_STORE = 'sql'
$env:AUTH_CONNECTION = 'sqlite'
php corevia auth:migrate
php corevia serve
```

SQLite uses the existing `SQLITE_DATABASE` setting; its parent directory must exist. Use `AUTH_CONNECTION=mysql` or `pgsql` for the named database connections documented in the V2 database guide. Supply their DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD privately. `AUTH_TABLE_PREFIX` defaults to `cv_auth` and accepts only safe lowercase identifiers.

Migration is explicit and idempotent. HTTP requests do not create or alter schema. Auth tables are separate from application/flow tables and use prepared statements, unique email constraints and expiry/user indexes. MySQL tables explicitly use InnoDB/utf8mb4; existing non-InnoDB tables are rejected because credential changes need real transactions. No import or destructive conversion happens when changing drivers. Back up/preserve existing users and plan a separate import before switching an existing application's store.

`SqlAuthStore` targets PDO SQLite, MySQL and PostgreSQL through `SqlConnection`. `AuthStore` remains the basic extension contract; `AccountStore` adds atomic token issuance/completion. JSON supports both contracts and reads existing format-1 users/sessions with version-zero defaults. MongoDB/other document auth adapters are not implemented by this phase; framework document APIs are separate.

SQL auth owns short transactions and serializes security-sensitive mutations with a database mutex. It rejects calls inside an application-owned transaction rather than reading a stale credential snapshot. Use a dedicated named auth connection if business code holds transactions. Set database lock/time limits appropriate to deployment; this baseline prioritizes atomic account changes over unrestricted write throughput.

## Try recovery and verification locally

1. Register at `/register`. A verification message is placed in the private development outbox.
2. Run `php corevia auth:outbox` to list message file names, recipients, purpose and expiry. Links are excluded from this listing.
3. Run `php corevia auth:outbox --show=<listed-file.json>` to inspect that one private development link. Treat the link as a credential; do not paste it into logs or public issues.
4. Open the verification link while signed in to that account, then click **Confirm email**.
5. At `/forgot-password`, request a recovery link, inspect its preview and use it to choose a new password.

Preview files live under private `storage/auth/outbox/`, are atomically written with private permissions, and are never served by an HTTP preview endpoint. They intentionally contain plaintext links like a mailbox; user/session/token records store only token digests. Protect/remove development previews as you would local mail. Older messages may have been invalidated by a later request even before their displayed expiry.

`AUTH_DELIVERY` defaults to `preview` outside production and `disabled` in production. Preview delivery and CLI outbox inspection refuse production mode. To deliver real mail, implement `Corevia\Auth\AccountDelivery`, then set AUTH_DELIVERY to its container-resolvable class name, or edit the composition factory to construct it explicitly. The interface receives `AccountMessage` with recipient, purpose, expiry, subject/text helpers and an explicit `link()` accessor. Its link is excluded from default JSON/public-property serialization. A production transport/queue must keep message links private; no SMTP/provider transport is silently configured or invoked by the framework.

Delivery/storage-issuance failures produce sanitized internal logs and the same generic request response; they do not expose recipient existence, provider credentials or bearer links. The API does not claim constant-time delivery across arbitrary custom transports; use queued delivery and abuse controls in production.

## Security semantics

- Reset links expire after 30 minutes; verification links after 24 hours. Tokens have 256 bits of randomness, are purpose/account/email/version-bound and single-use. Issuing another token for the same account/purpose invalidates the earlier link.
- Issuance compares the expected recipient and credential version atomically, so a concurrent account change cannot send a token valid for a different email snapshot. Custom adapters must preserve these atomic checks; out-of-band credential/email edits must also invalidate the corresponding version, sessions, tokens and verified status.
- Reset consumes the token and changes the password in the same transaction, increments the account credential version, and revokes all its sessions and outstanding account tokens. Session creation checks that version atomically, closing a concurrent old-password login race. Reset does not log the browser into the target account; the current browser session is cleared and the user signs in again.
- Verification requires a signed-in matching account. A different account cannot consume the token. Verification changes the verified timestamp but does not grant application roles or tenant permissions.
- Every mutation still needs a session-bound CSRF token and the configured origin. Attempt limits apply to requests and token completion. Password validation/hashing is shared with registration.
- Links use `#token=...`, not query/path bearer values. The UI captures the fragment, removes it from browser history and posts the token in the request body. This needs JavaScript; PHP confirmation forms still use safe POST fallback if scripts are unavailable, so passwords cannot fall into URL query strings. No password/token is passed through workflow state.

Enable `AUTH_REQUIRE_VERIFIED_EMAIL=true` to gate the bundled dashboard. An enabled delivery adapter is required for that option. Other routes can apply `RequireVerifiedEmail` **after** `SessionMiddleware`. Authentication/verification still do not replace authorization for business records. `/auth/verified` is a small protected JSON endpoint demonstrating the guard.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/forgot-password`, `/reset-password`, `/verify-email` | Account pages |
| POST | `/auth/password/forgot` | Generic reset request response |
| POST | `/auth/password/reset` | Consume token, replace password, revoke sessions |
| POST | `/auth/email/resend` | Authenticated verification request |
| POST | `/auth/email/verify` | Consume matching-account verification token |
| GET | `/auth/verified` | Verified-account guard example |

`/auth/session` adds `recovery` and `email_verified` status flags. Public user objects retain ID/name/email. Auth store/delivery/gate settings are included in effective configuration and signed-artifact drift verification. Rebuild artifacts after changing those policies.

## Validation and limits

Local tests cover JSON and SQLite contract parity, concurrent single-use reset, stale-login rejection, token purpose/expiry/account binding, session revocation, verified gates, delivery privacy and safe form fallback. Real browser flows cover every starter on JSON and SQLite. Bundled assets are rebuilt from pinned dependencies.

CI configuration also runs the storage contracts against MySQL/PostgreSQL services. Those live database-service checks have not been run locally for this phase, and GitHub CI is not run until publication is authorized. Production mail-provider integration, MFA/OAuth, account settings, document auth storage and distributed operational tooling remain separate work.
