# Optional live cancellation to Python workers

PHP can signal a running CVP/2 Python action through a separate authenticated
control request. This supplements deadline propagation; it does not replace it.
The feature is disabled unless a Python `Worker` explicitly receives a bounded
`CancellationRegistry` and worker authorization. The existing PHP action gateway
rejects this extension with `CANCELLATION_UNAVAILABLE`; it does not advertise a
cross-process PHP registry.

```python
from corevia import CancellationRegistry, WorkContext, Worker

worker = Worker('http://127.0.0.1:8090', token=private_token,
                timeout=60, cancellation=CancellationRegistry(256))

@worker.action('report.build')
def report(payload):
    context = WorkContext.current()
    for bounded_batch in batches(payload):
        context.check()
        process_batch(bounded_batch, timeout_ms=context.remaining_milliseconds())
    return {'completed': True}
```

Create a fresh private handle **before** dispatch. Keep it in an application-owned
authorized job/session record if another request will cancel it. Do not put the
capability in a URL, public asset, telemetry, ordinary log or browser storage.

```php
$handle = ['id' => bin2hex(random_bytes(16)), 'capability' => bin2hex(random_bytes(32))];
// Save privately before starting the call; authenticate/authorize its human owner.
$result = (new Corevia\Protocol\RemoteAction(
    'https://private-worker.example/cvp', $privateWorkerToken, 60.0,
    version: Corevia\Protocol\Protocol::V2,
))->call('report.build', $input, requestId: $handle['id'],
    cancelCapability: $handle['capability']);
```

From a separate authorized application request/process, retrieve that same handle
and issue the control with an independent short timeout:

```php
(new Corevia\Http\RemoteCancellation(
    new Corevia\Http\NativeHttpClient(timeout: 2),
    'https://private-worker.example/cvp/cancel',
    ['Authorization' => 'Bearer '.$privateWorkerToken],
))->request($handle['id'], $handle['capability']);
```

The application must enforce owner/tenant authority and browser CSRF protection
at its own cancellation route. Do not publish a service bearer or allow browsers
to call a privileged worker directly. A custom Python `authorize` callback must
explicitly permit `corevia.cancel`; the worker also rechecks current permission
for the original action target before signaling it. A shared static service
token authenticates the service, not a human or tenant. Public worker listeners
need a trusted TLS proxy, network access controls and connection/body/rate limits.

## Contract and limits

- CVP/2 call metadata adds `cancel_capability`, exactly 64 lowercase hex digits.
  PHP requires an explicit request ID and HTTPS, or literal loopback for a local
  fixture. A request ID is 1-128 UTF-8 bytes with no ASCII control characters.
- `POST /cvp/cancel` accepts only `request_id` and `capability` in a JSON body up
  to 1,024 bytes. It requires the normal worker authorization. Successful controls
  return HTTP 202 and `{"accepted":true}` whether the handle is active, unknown,
  already finished or has an incorrect capability. This hides existence.
- Acceptance acknowledges the control request, **not that work stopped**. Only
  a matching active handle with current authority sets its thread-safe deadline
  cancellation flag. The original action reports `WORK_CANCELLED` at its next
  checkpoint and still runs scope cleanup. That result is not retryable by default.
- Registration holds only a keyed digest of the capability, action target and
  deadline. Duplicate active IDs and a full registry fail without eviction;
  requests cannot replace another registration. Release uses entry identity so a
  stale cleanup cannot remove newer work. Finished handles are forgotten.
- No signal is queued for a call that has not registered yet. A cancel before
  acceptance can therefore be a no-op. Applications must coordinate acceptance
  or keep the ordinary propagated deadline authoritative; do not claim guaranteed
  cancellation delivery. Always use a fresh ID and capability per attempt.
- The registry belongs to **one worker process**. Route action and control to
  that same instance through application-owned routing. It is not shared across
  a load balancer, replica set, process pool or restarted process. There is no
  durable control mailbox or Python-to-PHP live cancellation receiver.
- Worker stop/shutdown closes registration and signals active cooperative calls
  before draining request threads. A closed registry must not be reused to start
  a replacement worker. Native/blocking callbacks may still need supervisor
  termination; a capability is not an OS sandbox or permission to undo effects.
- An uncertain timeout, cancellation or hard kill cannot roll back a sent email,
  paid inference or committed external write. Use stable downstream effect keys,
  fencing and application reconciliation. No automatic retry or refund is added.

`examples/cancellation/worker.py` is a loopback teaching worker requiring a private
`COREVIA_WORKER_TOKEN`; run it with `PYTHONPATH=sdk/python` from the checkout.
The PHP examples above can use `http://127.0.0.1:8090` for that fixture; enable
`allowLoopback:true` explicitly on both HTTP control objects. Never downgrade
non-loopback traffic to plaintext.

`python tests/v4/cancel-http.py` runs actual concurrent PHP action/control requests
against a private Python worker, checks wrong auth/capabilities and old handles,
then verifies cancellation, cleanup, malformed controls and owned-process shutdown.
Python unit tests cover capacity, concurrent signals, current target revocation,
stale release and shutdown. PHP contracts cover endpoint/metadata/response bounds
and unsupported gateway rejection. These are local interoperability checks; TLS,
real authorization-policy integration, replica routing, blocked native work and
sustained-load/security review remain acceptance work.
