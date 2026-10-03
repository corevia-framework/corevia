# Deployment, security defaults and V1 limits

## Local / shared hosting

The core runtime is PHP-only. With PDO SQLite and a private writable storage directory, HTTP flows persist across normal requests. Point the web server's document root at `public/`; route API paths to `public/index.php`. Never expose repository root or `storage/`. Apache example (`public/.htaccess`):

```apache
RewriteEngine On
RewriteRule ^ index.php [L]
```

The front controller has a narrow static allowlist and routes everything else through the runtime. The `serve` command uses PHP's development server and is intended for local development.

Shared hosts can serve the HTTP layer if they allow PHP/PDO SQLite and writable private storage. Realtime needs a long-running process and socket listeners, which shared hosts may not permit. In that case, run realtime on a VPS and configure an application broadcaster adapter for a secured transport. The bundled IPC adapter expects realtime and PHP on the same machine; it does not support remote IPC.

## VPS

Use PHP-FPM behind an HTTPS proxy. Keep the WebSocket listener bound to localhost and proxy a public WSS endpoint to it. Run `php corevia realtime:start` under a process supervisor (systemd/Supervisor or a Windows service manager). Forward WebSocket upgrade headers, configure idle timeouts above heartbeat interval and grant the service account access only to its private storage. Corevia does not terminate TLS itself.

```ini
# Example systemd service; adapt paths/user for your host.
[Unit]
Description=Corevia realtime
After=network.target

[Service]
Type=simple
User=corevia
WorkingDirectory=/srv/corevia
Environment=COREVIA_STORAGE=/var/lib/corevia
EnvironmentFile=/etc/corevia/environment
ExecStart=/usr/bin/php /srv/corevia/corevia realtime:start
Restart=on-failure
TimeoutStopSec=10

[Install]
WantedBy=multi-user.target
```

Python worker HTTP should also remain private behind a proxy/firewall and use a shared token or pluggable authorizer. Use a proper worker process supervisor for long-running handlers; the included threaded HTTP host is a reference/development server. Every network operation has a deadline; network timeouts do not cancel a side effect that a remote handler has already started.

## Configuration

| Environment variable | Default / purpose |
| --- | --- |
| `COREVIA_STORAGE` | `<project>/storage`; private SQLite/log/secret storage |
| `COREVIA_CONTROL_PORT` | 8082; loopback authenticated realtime IPC |
| `COREVIA_ORIGINS` | `http://localhost:8081,http://127.0.0.1:8081`; comma-separated exact origins |
| `COREVIA_PRIVATE_TOKEN` | Unset: deny all private channel subscriptions; sample development token policy when set |
| `COREVIA_API_TOKEN` | Unset: action gateway absent; when set, only `orders.get` is exposed |
| `COREVIA_DISABLED_MODULES` | Empty; comma-separated module IDs |
| `COREVIA_FRAUD_URL` | Unset: local demo risk rule; set to worker HTTP `/cvp` to use Python |
| `COREVIA_WORKER_URL` | `http://127.0.0.1:8090`; Python bind URL |
| `COREVIA_WORKER_TOKEN` | Shared development bearer token for PHP ↔ Python calls |
| `PYTHON`, `PHP_BINARY` | Integration-test executable overrides |

Do not commit credentials. The IPC secret is randomly generated in private storage and never sent to browser clients. Files created locally request restrictive permissions; configure equivalent Windows ACLs. Private subscription and API tokens are separate from the IPC secret. Origin checking allows non-browser clients without Origin; authentication/authorization must protect private data independently.

The sample checkout is an open development endpoint. Before serving real operations, add route authentication, ownership checks, ingress request/connection limits and application-specific validation. The action gateway has an explicit allowlist and mandatory authorization callback. Private channels deny by default. WebSocket inbound payloads, output buffering, subscriptions, connection counts, connection/message rates and stale clients are bounded. IPC binds to loopback and checks a shared secret. Untrusted clients cannot broadcast events or invoke actions through realtime.

Logs redact credential-named fields. Application messages and state snapshots are still application-controlled: do not put secrets/auth tokens in state or log messages. Configure filesystem access, retention and any encryption required for personal data. SQLite snapshots include the input state and are not automatically encrypted or redacted.

## Explicit V1 limits

- Sequential synchronous flows and bounded in-process retry delays. No durable multi-day waits, human approval or distributed sagas.
- Idempotency prevents concurrent duplicate starts and duplicate replay of persisted runs. This is not exactly-once side-effect execution: providers must support operation keys, and a process kill can leave a run `running`. There is no automatic recovery/replay of interrupted critical flows.
- Manual retries target failed non-critical steps using current persisted state. No automatic retry worker or dead-letter queue. A killed manual retry can leave an exclusive database lock flag requiring operator recovery after verifying no process is active; never clear it while a retry is running.
- SQLite is the reference PDO adapter. MySQL/Postgres drivers are not implemented; supply a RunStore adapter. Single-machine local persistence is not a distributed transaction manager.
- Module isolation catches registration/action/listener exceptions. PHP fatal resource exhaustion, termination and corrupt external infrastructure can affect the whole process. Module health shows boot state; action failures are available in run records and structured logs.
- WebSocket events are live and best effort, with possible duplicates and no durable replay. A broadcast acknowledgement confirms process acceptance only. Client reconnect restores channels; use HTTP to reconcile missed updates.
- Realtime IPC is single-host loopback TCP. No broker, clustering, multi-region routing or service discovery. TLS and deployment supervision belong to the host/proxy.
- Python handlers run in reference server threads without forced execution cancellation or a production job scheduler. Worker timeouts bound network I/O, not arbitrary handler code.
- React helpers are thin optional code; browser/React Native UI lifecycle was not tested with a device/browser automation suite. Transport SDK behavior is tested with mocks and real Node WebSocket clients.
- Shared-host HTTP support depends on host extensions/storage permissions; WebSocket hosting requires process/socket access.
- Demo ledger simulates order/payment/notification operations. Real payment/email integrations must supply their own validation, transaction/idempotency and delivery policies.
- Generators produce minimal runnable starting implementations and require explicit module registration. No implicit discovery.
- No Laravel/CodeIgniter compatibility, full ORM/CMS, visual workflow editor, Kubernetes, GraphQL, Flutter, native push or background mobile execution.

## Scheduled and event triggers

Use cron/Task Scheduler to invoke `php corevia flow:run <name> --input=file.json --key=<business-operation-key>`. Webhooks are normal authenticated POST flow routes. Event listeners can invoke `$app->run(...)` with a stable key; avoid recursive event cycles. Corevia V1 does not include an internal scheduler.
