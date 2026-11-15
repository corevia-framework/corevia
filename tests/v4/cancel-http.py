"""Actual concurrent PHP calls/control against one authorized Python worker; no external provider."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'sdk/python'))
from corevia import CancellationRegistry, WorkContext, Worker

ready = threading.Event()
started = threading.Event()
closed = []
worker = Worker('http://127.0.0.1:0', token='fixture', timeout=10, cancellation=CancellationRegistry(4))
worker.on_startup(lambda worker: ready.set())
worker.on_scope(lambda scope: scope.on_close(lambda: closed.append(True)))


@worker.action('slow')
def slow(payload):
    started.set()
    while True:
        WorkContext.current().check()
        time.sleep(0.005)


thread = threading.Thread(target=worker.run)
thread.start()
calls = []
try:
    assert ready.wait(5)
    origin = 'http://127.0.0.1:' + str(worker.server.server_address[1])
    php = shutil.which('php')
    if not php:
        raise RuntimeError('Actual PHP is required.')
    base = [php, str(ROOT / 'tests/v4/cancel-http.php'), origin]
    for turn in range(2):
        started.clear()
        capability = ('a' if turn == 0 else 'b') * 64
        call = subprocess.Popen(base + ['call', 'same-request-id', capability], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        calls.append(call)
        assert started.wait(5)
        if turn == 0:
            wrong = subprocess.run(base + ['cancel', 'same-request-id', capability, 'wrong-token'], capture_output=True, text=True, timeout=5)
            assert wrong.returncode != 0
        # Invalid/old capability is accepted without disclosing state and does not cancel the call.
        wrong = subprocess.run(base + ['cancel', 'same-request-id', ('c' if turn == 0 else 'a') * 64], capture_output=True, text=True, timeout=5)
        assert wrong.returncode == 0 and wrong.stdout.strip() == 'accepted'
        time.sleep(0.05)
        assert call.poll() is None and worker.cancellation.active_count() == 1
        start = time.monotonic()
        control = subprocess.run(base + ['cancel', 'same-request-id', capability], capture_output=True, text=True, timeout=5)
        assert control.returncode == 0, control.stderr
        output, error = call.communicate(timeout=5)
        assert call.returncode == 0, error
        assert json.loads(output) == {'ok':False,'code':'WORK_CANCELLED'}, output
        assert time.monotonic() - start < 3
        assert worker.cancellation.active_count() == 0 and len(closed) == turn + 1
        late = subprocess.run(base + ['cancel', 'same-request-id', capability], capture_output=True, text=True, timeout=5)
        assert late.returncode == 0
    # Oversized/malformed control is rejected without adding reservations.
    for body in [b'{}', b'x' * 1025]:
        request = urllib.request.Request(origin + '/cvp/cancel', data=body, headers={'Authorization':'Bearer fixture'})
        try:
            urllib.request.urlopen(request, timeout=3)
            raise AssertionError('Malformed control accepted.')
        except urllib.error.HTTPError as error:
            assert error.code == 400
    assert worker.cancellation.active_count() == 0 and WorkContext.current() is None
    print('PASS actual PHP/Python authenticated live cancellation, wrong/old capability denial, cleanup, reused-ID fencing and bounded controls')
finally:
    for child in calls:
        if child.poll() is None:
            child.terminate()
            child.wait(timeout=5)
    if worker.server is not None:
        worker.server.shutdown()
    thread.join(timeout=15)
    if thread.is_alive():
        raise RuntimeError('Owned worker failed to stop.')
