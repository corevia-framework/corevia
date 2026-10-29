"""Per-execution resource ownership, isolated across threads and async contexts."""
import contextvars
import logging
import uuid
from contextlib import contextmanager

_current = contextvars.ContextVar('corevia_scope', default=None)

class ExecutionScope:
    def __init__(self, kind, metadata=None):
        self.id = uuid.uuid4().hex
        self.kind = kind
        self.metadata = dict(metadata or {})
        self._instances = {}
        self._cleanup = []
        self._active = True

    @staticmethod
    def current():
        return _current.get()

    def on_close(self, callback):
        if not self._active:
            raise RuntimeError('Execution scope is closed.')
        self._cleanup.append(callback)

    def service(self, name, factory=None, cleanup=None):
        if not self._active:
            raise RuntimeError('Execution scope is closed.')
        if name not in self._instances:
            if factory is None:
                raise KeyError('Scoped service is not registered: ' + name)
            value = factory()
            self._instances[name] = value
            if cleanup is not None:
                self.on_close(lambda: cleanup(value))
        return self._instances[name]

    def close(self):
        if not self._active:
            return []
        self._active = False
        failures = []
        try:
            for callback in reversed(self._cleanup):
                try:
                    callback()
                except BaseException as error:
                    failures.append(type(error).__name__)
        finally:
            self._cleanup.clear()
            self._instances.clear()
        return failures

@contextmanager
def execution_scope(kind, metadata=None):
    scope = ExecutionScope(kind, metadata)
    token = _current.set(scope)
    failed = False
    try:
        yield scope
    except BaseException:
        failed = True
        raise
    finally:
        try:
            failures = scope.close()
        finally:
            _current.reset(token)
        if failures:
            try:
                logging.getLogger('corevia').error('Scope cleanup failed scope_id=%s classes=%s', scope.id, ','.join(failures))
            except Exception:
                pass
            if not failed:
                raise RuntimeError('SCOPE_CLEANUP_FAILED')
