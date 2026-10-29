# V3 foundations: local phase

This phase finishes the foundations previously marked partial. V3 is not released. Work stays on the local `develop/v3` branch until the complete V3 plan is ready; no push, PR, merge, tag or release is part of this phase.

| Plan point | Delivered in this phase | Scope |
| --- | --- | --- |
| 9. Deterministic module composition | Explicit canonical module/dependency order, topology/capability checks before registration, inspectable registration graph | Opt in with `compose()`. Existing V2 `modules()` keeps its behavior. |
| 11. Explicit execution lifecycle | Per-unit scopes, scoped DI, reverse cleanup, nested/Fiber isolation, singleton-capture guard; PHP HTTP/flow/CLI/job/websocket integration; Python per-dispatch and startup/shutdown hooks | Buffered HTTP responses and the existing synchronous/threaded workers. Custom adapters must enter their own scope. |
| 13. Reproducible config/build artifacts | Canonical effective config and graph, source/lock hashes, private HMAC artifact, inspect/verify commands, starter boot verification | Declared source inputs and declared registrations. This is not an executable cache or proof of arbitrary factory behavior. |
| 14. Cross-runtime contract manifest | One application manifest, portable schema validation, auth/error/event metadata, deterministic generated PHP/JS/TS/Python/Dart bindings, shared conformance fixtures | Explicit supported schema subset; auth enforcement remains the application's responsibility. |

Next foundation phases are capability presets/runtime profiles (#10), then machine-enforced architecture boundaries (#12). The other planned V3 workstreams, including one-command auth starters and AI tooling, require their own implementation and acceptance checks. Existing Python support does not itself provide model hosting, GPU scheduling, RAG or AI security policies.

## Validation

`php corevia test` includes the new composition, scope, adapter lifecycle, artifact boot and PHP contract checks. Generated bindings share 21 application fixtures across PHP, JS, Python and Dart. A TypeScript compilation checks valid calls and expected type errors. CI configuration includes these checks; updating the workflow locally does not run GitHub CI.

The foundation tests include shuffled module registration, byte-identical signed artifacts, source/lock drift, wrong keys/environment, malformed dependency plans, scoped resource disposal on success/failure, concurrent PHP Fibers and Python threads, HTTP/controller injection, queued jobs, websocket message callbacks, partial Python startup failure and guarded regeneration.

Full V2 regression and live transport suites remain required. Live MySQL/PostgreSQL/MongoDB service coverage belongs to the existing CI job and requires those services; database-free and SQLite checks can run locally. This phase does not certify all 14 V3 points or expand the V2 production-readiness claim to unreleased V3.

Local checks passed for the PHP suite/lint, database-free learning application, SQLite adapter, live HTTP/realtime/PHP-to-Python integration, 9 JS SDK tests, 12 Python SDK/lifecycle tests, shared generated contracts in all four languages, TypeScript declarations, and Dart analysis. GitHub CI and live MySQL/PostgreSQL/MongoDB checks have not been run for this local phase.
