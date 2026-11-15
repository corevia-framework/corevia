"""Actual loopback PHP/Python HTTP + stdio budget/trace contracts, no external service."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import time

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'sdk/python'))
from corevia import Client, Deadline, Telemetry, Worker, WorkContext, work_context

telemetry = Telemetry('python-contract', {'python.action':'server'})
worker = Worker('http://127.0.0.1:0', telemetry=telemetry)
calls, closed, ready = [], [], threading.Event()

@worker.on_startup
def startup(worker):
    ready.set()

@worker.on_scope
def scope(scope):
    scope.on_close(lambda: closed.append(True))

@worker.action('inspect')
def inspect(payload):
    calls.append('inspect')
    context = WorkContext.current()
    return {'remaining_ms':context.remaining_milliseconds(), 'traceparent':context.traceparent}

@worker.action('slow')
def slow(payload):
    calls.append('slow')
    context = WorkContext.current()
    while True:
        context.check()
        time.sleep(.005)

thread = threading.Thread(target=worker.run)
thread.start()
try:
    assert ready.wait(5), 'Loopback worker failed to start.'
    origin = 'http://127.0.0.1:' + str(worker.server.server_address[1])
    env = dict(os.environ, PYTHONPATH=str(ROOT/'sdk/python'))
    result = subprocess.run([shutil.which('php'),str(ROOT/'tests/v4/cross-runtime.php'),origin+'/cvp',sys.executable],env=env,cwd=ROOT,capture_output=True,text=True,timeout=15)
    assert result.returncode == 0, result.stderr
    report = json.loads(result.stdout)
    for _ in range(100):
        if len(closed) == 2:
            break
        time.sleep(.01)
    packet, dropped = telemetry.drain()
    spans = packet['resourceSpans'][0]['scopeSpans'][0]['spans']
    assert len(spans) == 2 and dropped == 0
    child = next(s for s in spans if s['traceId'] == 'a' * 32)
    assert child['parentSpanId'] == report['spans'][0]['spanId']
    assert child['spanId'] != report['spans'][0]['spanId']
    assert calls == ['inspect','slow'] and len(closed) == 2
    assert any(s['status']['code'] == 2 for s in spans)
    trace = '00-' + 'c' * 32 + '-' + 'd' * 16 + '-01'
    with work_context(Deadline(1000), trace):
        nested = Client(origin, version='2.0').call('inspect', {})
        assert nested['result']['traceparent'][3:35] == 'c' * 32
        assert nested['result']['remaining_ms'] <= 1000
    assert WorkContext.current() is None
finally:
    worker.stop()
    thread.join(timeout=5)
    assert not thread.is_alive()
print('PASS real PHP/Python HTTP+stdio budgets, W3C parent/child correlation, cancellation-before-dispatch, remote deadline and worker cleanup')
