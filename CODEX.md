# Corevia development

Preserve V1 at v1.0.0. Develop V2 on develop/v2 using scoped commits. Do not force-push, rewrite history, or silently update dependencies. Stable merges and tags follow owner acceptance and passing release checks.

Keep normal PHP applications free of mandatory Redis, Docker, Node, Python, or MySQL. Optional transports and SDKs must preserve fault isolation. Register actions through explicit module boundaries. Never automatically retry uncertain completed external side effects. Store configuration and runtime data outside public paths and keep credentials out of Git.

Run `php corevia test` for PHP checks and the CI commands for SDK and live integration checks. Document public API changes in CHANGELOG.md and docs/v2. `upgrade:check` is read-only. Do not mutate application source automatically during upgrades.
