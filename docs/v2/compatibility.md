# V1 compatibility in V2

Existing `Module` implementations, `Action::handle`, `State`, flow definitions, `Corevia::create` positional arguments, `boot` module configuration, container `set`, events, MemoryRunStore, and SQLite PdoRunStore remain supported. Module manifests and lifecycle hooks are optional. No service discovery network calls or mandatory broker, database, or JavaScript runtime were added.

Execution requires a ready kernel. Register modules and hooks before boot, register actions/flows/listeners during module registration, and create a new app instance after shutdown. Calling registration methods later now raises a lifecycle error. Avoid directly mutating the public registration arrays to bypass these guards.

HTTP and CLI adapters now release module resources at completion. Long-lived custom workers should use `try/finally` around boot and their execution loop. Application readiness hooks run after the runtime is assembled; module readiness hooks run before assembly.

V2 keeps CVP/1 and the existing JavaScript/Python APIs, and adds explicit CVP/2 selection, compensation, pause/resume, retry policies, SDK facades and installable package manifests. The development version is `2.0.0-alpha.2`; no stable V2 tag is published before acceptance. Remote HTTP endpoints outside loopback now require a bearer token or signing policy, and URLs with embedded credentials are rejected. See migration.md for the full upgrade checklist.

Validation includes the original V1 suite, the task learning app, SDK tests, live HTTP/CLI/worker/WebSocket integration, and the database-free kernel suite.
