# Optional passkey acceptance dependencies

This private Composer project pins the verifier used by the WebAuthn browser tests. It is not required by ordinary Corevia applications or the default framework test suite. Vendor files stay ignored.

```sh
composer install --no-interaction --no-scripts --no-plugins
composer audit --locked
```

From the repository root, run `node tests/v4/passkey-browser.mjs` with the tools dependencies and a Playwright Chromium installation, or set `COREVIA_BROWSER` to an installed Chrome executable. Tests use private disposable JSON/SQLite stores and a virtual authenticator, not a person's real device or provider account.
