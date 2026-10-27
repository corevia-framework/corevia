# CVP/2 and external runtimes

Core accepts CVP/1 and CVP/2, rejects other versions, and replies in the request's version. Existing constructors keep the CVP/1 default. Opt into CVP/2 with `RemoteAction(..., version: Protocol::V2)`, Python `Client(..., version="2.0")`, or JavaScript `createCorevia({baseURL: '/api'})`.

CVP/2 requests contain `cvp`, `id`, `type`, `target`, `payload`, and `meta`. Metadata supports `trace_id`, `parent_trace_id`, and `timeout_ms` (integer 1–300000). Replies use `type: action.result`, echo the ID, and carry `ok`, `result`, `error`, and trace metadata. Errors contain code, message, and retryable. A client rejects version downgrade and mismatched correlation IDs. See `protocol/v2/fixtures.json` for shared examples.

Expose HTTP actions only with Router's explicit allowlist and authorization callback. Bearer tokens work in all clients. PHP RemoteAction also accepts a `sign` closure receiving the exact encoded body and returning HTTP headers; verify the raw body in your gateway authorization policy. Use HTTPS across machines, restrict outbound URLs, and provide replay protection in your signature policy. Transport errors use `REMOTE_*` or `PROCESS_*`; business codes remain distinct. Timeout metadata expresses a deadline; transport limits enforce it. In-process handlers must bound their own blocking operations.

For a trusted local executable, `ProcessTransport([$pythonExecutable, $workerScript], timeoutMs: 5000)->call('ai.analyze', $input)` sends one CVP/2 JSON line on stdin and expects one response on stdout. Logs belong on stderr. The command bypasses shell interpolation. Time and output limits terminate the child; crashes and malformed replies are classified separately. Keep command configuration trusted. Python is never required by the PHP runtime.

Bind RemoteActionBinding to a configured instance in the container to register a remote call through the same Action interface used by flows. The Python decorator registers actions on its explicitly configured worker; no unauthenticated discovery service starts.

```javascript
import { createCorevia } from '@corevia/js';
const corevia = createCorevia({baseURL: '/api', realtimeUrl: 'wss://example.com/realtime', token: () => accessToken});
const result = await corevia.action('orders.create', {item: 42});
const off = corevia.channel('orders').on('OrderUpdated', event => updateUI(event));
```

`off()` removes the handler; channel.unsubscribe() removes the subscription. React uses CoreviaContext and useCoreviaAction on this client; React Native reuses it with an injected WebSocket when needed. Neither contains business logic. Python and JavaScript validate the same V1 and V2 fixtures as PHP.
