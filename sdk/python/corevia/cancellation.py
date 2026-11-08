"""Optional bounded cancellation for one worker process; no pending signals or durable queue."""
import hashlib
import hmac
import re
import secrets
import threading
from dataclasses import dataclass


class CancellationError(RuntimeError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def validate_control(request_id, capability):
    if not isinstance(request_id, str) or not 1 <= len(request_id.encode('utf-8')) <= 128 or any(ord(c) < 32 or ord(c) == 127 for c in request_id):
        raise ValueError('Invalid cancellation request ID.')
    if not isinstance(capability, str) or re.fullmatch(r'[0-9a-f]{64}', capability) is None:
        raise ValueError('Use a fresh 32-byte hexadecimal cancellation capability.')


@dataclass(frozen=True, repr=False)
class _Active:
    digest: bytes
    target: str
    deadline: object


class CancellationRegistry:
    def __init__(self, capacity=256):
        if type(capacity) is not int or not 1 <= capacity <= 4096:
            raise ValueError('Cancellation capacity must be 1..4096.')
        self._capacity = capacity
        self._key = secrets.token_bytes(32)
        self._active = {}
        self._closed = False
        self._lock = threading.Lock()

    def _digest(self, capability):
        return hmac.digest(self._key, capability.encode('ascii'), hashlib.sha256)

    def register(self, request_id, capability, target, deadline):
        validate_control(request_id, capability)
        entry = _Active(self._digest(capability), target, deadline)
        with self._lock:
            if self._closed:
                raise CancellationError('CANCELLATION_SHUTDOWN')
            if request_id in self._active:
                raise CancellationError('REQUEST_ALREADY_ACTIVE')
            if len(self._active) >= self._capacity:
                raise CancellationError('CANCELLATION_CAPACITY')
            self._active[request_id] = entry
        return entry

    def release(self, request_id, entry):
        with self._lock:
            if self._active.get(request_id) is entry:
                del self._active[request_id]

    def signal(self, request_id, capability, authorize):
        validate_control(request_id, capability)
        with self._lock:
            entry = self._active.get(request_id)
        if entry is None or not hmac.compare_digest(entry.digest, self._digest(capability)):
            return
        # Current target policy is checked outside the registry lock.
        if not authorize(entry.target):
            return
        with self._lock:
            if self._active.get(request_id) is entry:
                entry.deadline.cancel()

    def active_count(self):
        with self._lock:
            return len(self._active)

    def close(self):
        with self._lock:
            self._closed = True
            for entry in self._active.values():
                entry.deadline.cancel()
