import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'sdk' / 'python'))
from corevia import Worker, CoreviaError

worker = Worker(os.environ.get('COREVIA_WORKER_URL', 'http://127.0.0.1:8090'), token=os.environ.get('COREVIA_WORKER_TOKEN'))


@worker.action('fraud.check')
def fraud_check(payload):
    total = payload.get('total') if isinstance(payload, dict) else None
    if isinstance(total, bool) or not isinstance(total, (int, float)) or total < 0:
        raise CoreviaError('VALIDATION_FAILED', 'A non-negative total is required.')
    return {'approved': total <= 10000, 'risk': 'low' if total <= 10000 else 'high'}


@worker.action('ai.analyze')
def analyze(payload):
    text = payload.get('text') if isinstance(payload, dict) else None
    if not isinstance(text, str):
        raise CoreviaError('VALIDATION_FAILED', 'text must be a string.')
    return {'words': len(text.split()), 'characters': len(text)}


if __name__ == '__main__':
    print(f'Corevia Python worker on {worker.host}:{worker.port}', flush=True)
    try:
        worker.run()
    except KeyboardInterrupt:
        pass
