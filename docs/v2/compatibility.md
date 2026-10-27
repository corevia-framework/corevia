# V1 compatibility in V2

Existing `Module` implementations, `Action::handle`, `State`, flow definitions, `Corevia::create` positional arguments, `boot` module configuration, container `set`, events, MemoryRunStore, and SQLite PdoRunStore remain supported. Module manifests and lifecycle hooks are optional. No service discovery network calls or mandatory broker, database, or JavaScript runtime were added.

Execution requires a ready kernel. Register modules and hooks before boot, register actions/flows/listeners during module registration, and create a new app instance after shutdown. Calling registration methods later now raises a lifecycle error. Avoid directly mutating the public registration arrays to bypass these guards.

HTTP and CLI adapters now release module resources at completion. Long-lived custom workers should use `try/finally` around boot and their execution loop. Application readiness hooks run after the runtime is assembled; module readiness hooks run before assembly.

V2 keeps CVP/1 and the existing JavaScript/Python APIs, and adds explicit CVP/2 selection, compensation, pause/resume, retry policies, SDK facades and installable package manifests. The release version is `2.0.0`. Remote HTTP endpoints outside loopback require a bearer token or signing policy, and URLs with embedded credentials are rejected. See migration.md for the full upgrade checklist. The root homepage now uses the database-free web starter; the original checkout page lives at `/demo.html`, with its SQLite-backed API preserved.

Validation includes the original V1 suite, the task learning app, SDK tests, live HTTP/CLI/worker/WebSocket integration, and the database-free kernel suite.
