# V1 compatibility in M1

Existing `Module` implementations, `Action::handle`, `State`, flow definitions, `Corevia::create` positional arguments, `boot` module configuration, container `set`, events, MemoryRunStore, and SQLite PdoRunStore remain supported. Module manifests and lifecycle hooks are optional. No service discovery network calls or mandatory broker, database, or JavaScript runtime were added.

Execution requires a ready kernel. Register modules and hooks before boot, register actions/flows/listeners during module registration, and create a new app instance after shutdown. Calling registration methods later now raises a lifecycle error. Avoid directly mutating the public registration arrays to bypass these guards.

HTTP and CLI adapters now release module resources at completion. Long-lived custom workers should use `try/finally` around boot and their execution loop. Application readiness hooks run after the runtime is assembled; module readiness hooks run before assembly.

This milestone keeps CVP/1 and the current JavaScript/Python SDK APIs. Compensation, durable pause/resume, new retry policies, CVP/2, SDK redesign, and package distribution changes belong to later milestones. The runtime capability's `2.0.0-alpha.1` identifies this development stage; no V2 release tag is published here.

Validation includes the original V1 suite, the task learning app, SDK tests, live HTTP/CLI/worker/WebSocket integration, and the database-free kernel suite.
