# Security reports

Use GitHub's private **Report a vulnerability** option when available. If it is unavailable, ask the maintainer for a private reporting channel without posting exploit details, credentials or personal data publicly.

Do not commit database passwords, authentication files, `.env` files, private keys or generated storage. A credential committed in the past must be revoked even after a later commit removes it. Git ignore rules do not remove history and are not an access-control mechanism.

Serve only public/ as the document root. Review docs/deployment.md and docs/v2/databases.md for deployment boundaries. Framework authorization and application access policies remain explicit; the starter form's CSRF middleware does not replace authentication.
