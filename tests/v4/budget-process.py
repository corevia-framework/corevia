"""Trusted stdio fixture. No network calls or user executable selection."""
import json
import sys
from corevia import Worker, WorkContext

worker = Worker()

@worker.action('inspect')
def inspect(payload):
    context = WorkContext.current()
    return {'remaining_ms':context.remaining_milliseconds()}

envelope = json.loads(sys.stdin.readline())
print(json.dumps(worker.dispatch(envelope)))
