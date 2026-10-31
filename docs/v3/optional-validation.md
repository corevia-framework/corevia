# Optional provider and container acceptance

The maintainer explicitly deferred live OAuth login and Docker image validation for this release scope. They are **unverified**, not passed. The default application does not enable OAuth. The Dockerfile is an optional recipe; deploy the image only after target validation. Required database, production runtime, SMTP, regression and GitHub CI gates remain separate.

## OAuth/OIDC self-test

1. Generate an auth kit (`php corevia starter:auth --stack=bootstrap`, or `tailwind`/`react`) in a disposable application. Configure its own HTTPS `APP_URL`, `APP_HTTPS=true`, private storage and persisted encryption key. Use dedicated provider clients/test users.
2. Register the exact callback `<APP_URL>/auth/oauth/github/callback` or `<APP_URL>/auth/oauth/google/callback`. Set `AUTH_GITHUB=true` with `AUTH_GITHUB_ID/SECRET`, or `AUTH_GOOGLE=true` with `AUTH_GOOGLE_ID/SECRET`, in the server process environment. Keep secrets outside browser code/source control. The framework does not load `.env` automatically.
3. Register a local test account and sign in. Link the provider from account settings using the required password/MFA confirmation. Matching email claims never automatically link another account.
4. Sign out and complete provider login. Verify the linked account opens; an MFA-enabled account must still complete its factor. Cancel consent and verify no authenticated session appears.
5. Verify replayed/expired callbacks and callbacks in a different browser/session fail. Unlink the provider and verify it cannot authenticate that account. Use isolated fixtures for deliberately wrong issuer/audience/nonce/signature cases; never weaken the verifier to make a provider work.
6. Record source revision, provider/client configuration (no secrets), callback origin, outcome and timestamp in private acceptance records. Set the provider flag back to false if acceptance fails. Do not put callback codes/state, access tokens or screenshots containing credentials into public issues/logs.

The built-in OIDC client uses pinned HTTPS endpoints and RS256 validation; it is not an implementation of every algorithm/flow or an OIDC certification claim. Follow the provider's official [GitHub authorization flow](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps) or [Google OIDC documentation](https://developers.google.com/identity/openid-connect/openid-connect).

## Docker self-test

From a disposable application/source checkout with Docker installed:

```sh
docker build -f deploy/Dockerfile -t corevia-v3-test .
docker run --rm --entrypoint php corevia-v3-test corevia about
docker run --rm -e APP_ENV=production -e COREVIA_PROFILE=api --entrypoint php corevia-v3-test corevia doctor
docker image inspect corevia-v3-test --format '{{.Id}}'
```

Then test the intended application with private writable storage, secret mounts, the selected database drivers, signed build/verified boot and a TLS nginx edge. Keep FPM private. Exercise health/app routes, private-file denial, secure cookies, session revocation, bounded workers, streaming/cancellation where enabled, restart persistence and backup/code rollback. A successful build or `about` command alone does not establish production acceptance. MongoDB is an optional image extension, as documented in [deployment](../../deploy/README.md).

Pin reviewed `PHP_IMAGE` and `COMPOSER_IMAGE` digest build arguments for a production build; record the image ID/digests and target results. Do not copy credentials or private verification artifacts into the build context. Avoid production volume mounts/databases for these checks.

The `container` CI job is opt-in: manually run the **Corevia tests** workflow with `validate_docker=true`. Normal push/PR CI still runs the mandatory PHP, browser, SDK and database jobs. GitHub documents [typed manual workflow inputs](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#onworkflow_dispatchinputs). A skipped optional job must not be recorded as successful image validation.
