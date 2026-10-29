# Changelog

## Unreleased

- Add local V3 SQL auth storage/migrations, credential-version session checks, atomic recovery/verification tokens, private preview delivery and a production delivery port. Add recovery/verification pages across all starters and verified-email middleware. MySQL/PostgreSQL service checks are configured in CI; local coverage includes JSON and SQLite.
- Add the local V3 base auth milestone: safe one-command Bootstrap/Tailwind/React starters with offline assets, session/password services, private JSON storage, CSRF/origin checks, rotation/logout, attempt limits and browser coverage. SQL auth storage, recovery/verification and MFA remain separate phases.
- Add V3 runtime profiles with a lazy module catalog, profile-aware database-free API/worker/realtime boot, feature guards and artifact profile verification. Add static PHP/Python/frontend architecture rules, negative fixtures and local CI configuration.
- Begin V3 foundations: explicit canonical module composition and graph inspection; private signed config/source artifacts with drift verification; scoped HTTP, flow, CLI, job, websocket and Python work; shared application-contract generation for PHP, JavaScript/TypeScript, Python and Dart. See `docs/v3/foundations.md` for opt-in APIs and limits. This is not a V3 release.
- Prepare public source downloads with local-only ignore rules, archive exclusions, contributor/security guidance and first-application setup instructions.

## 2.0.0 — 28 October 2026

- Add named lazy SQL/document connections, prepared query builder, nested transactions and explicit portable SQL flow-store migration for MySQL, PostgreSQL and SQLite.
- Add optional native MongoDB and local JSON document adapters with a shared contract and extensible connectors.
- Add a database-free responsive starter website, web routes file, controllers, escaped PHP views, named URL generation, groups, constraints, resource routes and safe local redirects.
- Add signed CSRF middleware, validation examples, HTML/HEAD responses and database CLI commands.
- Add real database-service CI contracts and live starter-page/form tests.

## Unreleased — V2 M2/M3

- Add durable pause/resume, event and human checkpoints, compensation records, retry policies, output contracts, and cooperative action deadlines.
- Add a database-free JSON run store and an optional parallel-executor contract with conflicting-output rejection.
- Add dual CVP/1 and CVP/2 envelopes, version-matched replies, parent trace metadata, signed HTTP request hooks, and a bounded local process transport.

## Unreleased — V2 M4–M7

- Add authorized presence, public wildcard rooms, versioned events, reconnect metadata, and optional pub/sub fan-out.
- Add the JavaScript createCorevia/action facade and dual-version Python clients/workers with shared contract fixtures.
- Add run inspection, scheduled jobs, configuration cache commands, event generation, compatibility scans and explicit update checks.
- Add persisted per-attempt timing and exception-class metadata without exposing exception messages.
- Rename the Composer skeleton to corevia-framework/corevia, make PDO optional, and prepare a separable core manifest without publishing packages.

## Unreleased — V2 M1

- Add kernel lifecycle states, timing records, readiness/shutdown hooks, and execution guards.
- Add optional module manifests and lifecycle hooks with failure isolation and dependent cleanup.
- Add configuration overlays, JSON cache, lazy service factories, and capability discovery.
- Release module resources in the bundled HTTP and CLI adapters.
- Add a database-free kernel example, regression tests, and migration guidance.
- Preserve V1 flow, protocol, SDK, and optional database behavior.

## 1.0.0

Preserved the original modular action/flow runtime, CVP/1 interoperability, HTTP/CLI/realtime adapters, SDKs, SQLite demo, and database-free task learning project on `main` and the `v1.0.0` tag.
