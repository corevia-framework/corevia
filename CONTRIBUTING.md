# Contributing to Corevia

Applications belong in app/, routes/, resources/views/, public/assets/ and config/settings/. Framework changes belong in packages/ and should be proposed in a focused pull request with documentation and meaningful regression checks.

Run `composer install`, `composer validate --strict`, `composer lint`, and `composer test`. Full SDK, database-service and live integration checks are listed in .github/workflows/ci.yml. Normal PHP applications must not gain mandatory Node, Python, Redis, Docker or database-server dependencies.

Keep tokens, local credentials, signing material and runtime data out of commits. Existing release history and tags must remain intact; do not force-push or rewrite shared history. Follow semantic versioning and document public API changes in CHANGELOG.md. Source ZIPs omit repository automation; clone the repository to contribute to CI.
