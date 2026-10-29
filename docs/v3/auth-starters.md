# One-command auth starters: local V3 phase

In a fresh V3 Corevia checkout/application, run one command:

```sh
php corevia starter:auth --stack=bootstrap
# Alternatives: --stack=tailwind or --stack=react
php corevia serve
```

Open **http://127.0.0.1:8081/register**. Register an account, open your protected dashboard, sign out and sign in again. All three stacks ship compiled CSS; React also ships its production JavaScript bundle. This startup needs only PHP 8.2+ and JSON: no MySQL, Node installation, CDN, Composer download or frontend build step. The generator never invokes npm or starts another service.

Use one stack per installation. The installer adds files and refuses to overwrite existing application files; it does not install auth into the framework checkout automatically while V3 is being developed.

```sh
php corevia starter:auth --stack=react --dry-run
php corevia starter:auth --stack=react --path=path/to/existing-v3-app
php corevia starter:auth --stack=react --check
```

`--dry-run` lists proposed files without writing. `--path` must point to an existing V3 application with the auth package. `--check` compares an installed kit with its original templates; intentional application edits will show as drift. There is no force-overwrite option. Installation uses exclusive creation, an installation lock, symlink checks and rollback of files created by a failed operation. It leaves existing routes/pages intact and adds `/login`, `/register` and `/dashboard`.

## What is generated

| Location | Purpose |
| --- | --- |
| `config/auth.php` | UI stack, trusted APP_URL, registration policy and private store path |
| `routes/auth.php` | Explicit service/store wiring and auth routes |
| `resources/views/auth/` | Escaped PHP forms/dashboard, or the React HTML shell |
| `public/assets/auth.*` | Prebuilt framework CSS and React JS where applicable |
| `public/assets/auth-LICENSES.txt` | Bundled third-party license notices |
| `frontend/auth/` | Editable UI/build source and pinned dependency lock |
| `.corevia-auth-starter.json` | Original generated-file hashes; no credentials |
| `AUTH_STARTER.md` | Application-specific startup and customization instructions |

The bundled web bootstrap loads `routes/auth.php` only when present, under Web/Full profiles, and declares the configured `auth.session` capability. No auth routes or user records are created in existing applications merely by upgrading the framework. API-only profiles keep their minimal route set; custom API projects can wire `AuthService` and `SessionMiddleware` explicitly.

## Authentication behavior

Passwords are validated, confirmed and hashed with bcrypt cost 12. New passwords need at least 12 Unicode characters and at most 72 UTF-8 bytes; null bytes are rejected so bcrypt cannot silently truncate credentials. Sign-in failures use one generic message and a dummy hash for unknown accounts. Email identifiers are normalized to lower case. User responses contain only ID, display name and email; registration inputs cannot assign roles or other account privileges.

Sessions use random 256-bit opaque cookies. Only the token digest is stored, and cookies are HttpOnly, SameSite=Lax and scoped to `/` without a Domain attribute. HTTPS uses the `__Host-` cookie prefix and Secure. A session expires after eight hours; login/registration rotates both the session and CSRF token and revokes the old session. Logout revokes the session server-side and expires the browser cookie. No per-user request state is retained in process globals.

Every state-changing auth route requires a session-bound CSRF token. Origin/referrer checks use the configured APP_URL, not a client-controlled Host/X-Forwarded-Host value. Form posts carry `_token`; JSON posts use `X-CSRF-Token`. Issuance and login/registration attempts have persistent limits; proxy-forwarded addresses are not trusted implicitly. Behind a proxy, limits currently see its REMOTE_ADDR, so configure an edge limit/trusted-proxy strategy for your deployment before scaling this reference setup.

Auth responses disable caching and apply CSP, frame restrictions and content-type protection. PHP templates escape account names and errors; React renders them as text. Passwords are passed directly to auth services, never through flow state or persisted workflow inputs. Existing HTTP response support now preserves multiple Set-Cookie headers instead of overwriting cookies from another middleware.

## Same-origin JSON API

| Method | Route | Result |
| --- | --- | --- |
| GET | `/auth/session` | Anonymous/authenticated session bootstrap, CSRF and registration policy |
| GET | `/auth/me` | Public account fields, or 401 |
| POST | `/auth/register` | New account/session, or validation/registration error |
| POST | `/auth/login` | New session and CSRF token, or generic credential error |
| POST | `/auth/logout` | Revoked session and expired cookie |

These are JSON business endpoints, separate from CVP envelopes. React uses fetch with same-origin credentials. The server session cookie remains inaccessible to JavaScript. CSRF failures return 419; the router may reject forbidden origins earlier with 403. Attempt limits return 429 and Retry-After. Protect business routes with the explicit role/tenant/ownership [authorization layer](identity-mail-authorization.md#authorization); authentication alone does not grant resource access.

## Storage and deployment scope

The default `JsonAuthStore` is a bounded, single-node reference adapter. It uses a separate lock, private directories/files and atomic replacement, enforces unique email creation under the lock, expires session/limit records, and preserves corrupt input instead of silently resetting accounts. Records live under private `storage/auth/` and are ignored by Git. Serve only `public/`, preserve storage across restarts, and apply private filesystem permissions/Windows ACLs and appropriate backups. It is not a shared multi-node store.

`AuthStore` is an explicit persistence contract. The [storage/recovery phase](auth-storage-recovery.md) adds SQLite/MySQL/PostgreSQL auth, password reset, email verification, private preview delivery and a production delivery port. [Identity/mail/authorization](identity-mail-authorization.md) extends both stores with MFA, provider-neutral OAuth, remember-me, account settings, queued TLS-only SMTP and scoped permissions. These need the new kit wiring; upgrading older generated files is a deliberate merge. Existing flow tables are not auth tables, and selecting SQL does not import JSON users. Document auth adapters and distributed infrastructure remain pending.

APP_URL must match the browser origin. For another local port, set it explicitly:

```powershell
$env:APP_URL = 'http://127.0.0.1:8090'
php corevia serve --port=8090
```

Production auth refuses an HTTP APP_URL when APP_ENV=production. Use HTTPS at your deployment proxy, set APP_URL to its public origin and APP_HTTPS=true, and review registration policy (`AUTH_REGISTRATION=false` disables new accounts). Environment templates are documentation; the framework does not implicitly load `.env` files. Auth settings join effective application configuration so signed build verification detects policy changes. Recompile artifacts after installing or changing auth configuration.

## Customize and verify

From `frontend/auth`, run `npm ci`, edit the PHP views/JSX/CSS, then `npm run build`. Bootstrap and Tailwind provide PHP-rendered forms; React provides the same backend behavior with a React interface styled by Tailwind. Dependencies are pinned per stack. Tailwind scans only declared UI source inputs; it does not discover classes from arbitrary directories. Rebuilt assets go to `public/assets`. Preserve bundled license notices when distributing.

Framework contributors can run:

```sh
php -n tests/v3/auth.php
node tools/build-auth-starters.mjs --check
# Install tools dependencies first, then a browser (or set COREVIA_BROWSER).
node tests/v3/auth-browser.mjs
```

Browser tests generate isolated apps, register/login/logout through every real UI, verify escaping and session rotation, reject CSRF/foreign origins/private-file access, check credential privacy and installer conflicts, and block every external network request. CI configuration includes rebuilding bundled assets and browser tests; local development does not push or run GitHub CI.
