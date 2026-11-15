"""Private, single-process teaching worker. See docs/v4/live-cancellation.md."""
import os
import time
from corevia import CancellationRegistry, WorkContext, Worker

token = os.environ.get('COREVIA_WORKER_TOKEN', '')
if len(token) < 32:
    raise SystemExit('Set a dedicated COREVIA_WORKER_TOKEN of at least 32 characters privately.')
worker = Worker('http://127.0.0.1:8090', token=token, timeout=60, cancellation=CancellationRegistry(32))


@worker.action('example.slow')
def slow(payload):
    context = WorkContext.current()
    for step in range(600):
        context.check()
        # Replace with bounded I/O; this sleep represents cooperative work.
        time.sleep(0.05)
    return {'completed': True}


if __name__ == '__main__':
    try:
        worker.run()
    except KeyboardInterrupt:
        pass
