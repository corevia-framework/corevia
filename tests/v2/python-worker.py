import os
import time
from corevia import Worker
worker = Worker(os.environ['V2_WORKER_URL'], token='test-only-token')
@worker.action('echo')
def echo(payload):
    if payload.get('sleep'):
        time.sleep(0.5)
    return payload
worker.run()
