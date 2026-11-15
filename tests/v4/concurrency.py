"""Separate-process SQLite races for V4 budgets, optimistic writes and outbox claims."""
import concurrent.futures
import json
from pathlib import Path
import shutil
import subprocess
import uuid
import time

ROOT = Path(__file__).resolve().parents[2]
directory = ROOT / 'storage' / ('v4-concurrency-' + uuid.uuid4().hex)
directory.mkdir(mode=0o700)
php = shutil.which('php')
if not php:
    raise SystemExit('PHP with PDO SQLite is required.')
worker = str(ROOT / 'tests/v4/concurrency-worker.php')
subprocess.run([php, worker, 'prepare', str(directory)], cwd=ROOT, capture_output=True, check=True)


def run(number):
    started=time.monotonic()
    result = subprocess.run([php, worker, 'work', str(directory), str(number)], cwd=ROOT, capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise AssertionError(result.stderr)
    return {**json.loads(result.stdout), 'worker_seconds':round(time.monotonic()-started,3)}


with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    results = list(pool.map(run, range(8)))
assert len({row['dispatch'] for row in results}) == 1, results
assert sum(row['claimed'] for row in results) == 1, results
assert {row['receipt_revision'] for row in results} == {1}, results
assert {row['receipt_sequence'] for row in results} == {2}, results
assert sum(row['activated'] for row in results) == 1, results
assert sum(row['tool_consumed'] for row in results) == 1, results
assert sum(row['probe'] for row in results) == 1, results
assert sum(row['retries'] for row in results) == 10, results
assert sum(row['overlap'] for row in results) == 1, results
assert sum(row['reserved'] for row in results) == 10, results
assert sum(row['mutation'] for row in results) == 1, results
assert sum(row['sent'] for row in results) == 12, results
deliveries = (directory / 'deliveries.txt').read_text().splitlines()
assert len(deliveries) == len(set(deliveries)) == 12
print('Worker durations (seconds): '+str([row['worker_seconds'] for row in results]))
print('PASS 8-process SQLite WAL races: budget cannot overspend; one optimistic writer; unique active outbox claims; deduplicated dispatch and single overlap lease; one circuit probe and bounded shared retries; one active document writer and one tool approval consumer; one shared sync mutation receipt')
