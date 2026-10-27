# Changelog

## Unreleased — V2 M2/M3

- Add durable pause/resume, event and human checkpoints, compensation records, retry policies, output contracts, and cooperative action deadlines.
- Add a database-free JSON run store and an optional parallel-executor contract with conflicting-output rejection.
- Add dual CVP/1 and CVP/2 envelopes, version-matched replies, parent trace metadata, signed HTTP request hooks, and a bounded local process transport.

## Unreleased — V2 M1

- Add kernel lifecycle states, timing records, readiness/shutdown hooks, and execution guards.
- Add optional module manifests and lifecycle hooks with failure isolation and dependent cleanup.
- Add configuration overlays, JSON cache, lazy service factories, and capability discovery.
- Release module resources in the bundled HTTP and CLI adapters.
- Add a database-free kernel example, regression tests, and migration guidance.
- Preserve V1 flow, protocol, SDK, and optional database behavior.

## 1.0.0

Preserved the original modular action/flow runtime, CVP/1 interoperability, HTTP/CLI/realtime adapters, SDKs, SQLite demo, and database-free task learning project on `main` and the `v1.0.0` tag.
