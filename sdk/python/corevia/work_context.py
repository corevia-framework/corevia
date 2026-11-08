"""Cooperative, monotonic work budgets and data-free trace propagation."""
import contextvars
import math
import re
import time
import uuid
from contextlib import contextmanager

_current = contextvars.ContextVar('corevia_work', default=None)


class WorkBudgetError(RuntimeError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


class Deadline:
    def __init__(self, milliseconds, clock=time.monotonic_ns):
        if type(milliseconds) is not int or not 1 <= milliseconds <= 3600000:
            raise ValueError('Deadline must be 1ms..1h.')
        self._clock = clock
        self._end = clock() + milliseconds * 1000000
        self._cancelled = False

    def cancel(self):
        self._cancelled = True

    def check(self):
        if self._cancelled:
            raise WorkBudgetError('WORK_CANCELLED')
        if self._clock() >= self._end:
            raise WorkBudgetError('DEADLINE_EXCEEDED')

    def remaining_milliseconds(self):
        self.check()
        return max(1, math.ceil((self._end - self._clock()) / 1000000))


def parse_traceparent(value):
    if not isinstance(value, str):
        return None
    match = re.fullmatch(r'00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})', value)
    if not match or match[1] == '0' * 32 or match[2] == '0' * 16:
        return None
    return value


def child_traceparent(parent):
    if parse_traceparent(parent) is None:
        raise ValueError('Invalid trace context.')
    return '00-' + parent[3:35] + '-' + uuid.uuid4().hex[:16] + '-' + ('01' if int(parent[-2:], 16) & 1 else '00')


class WorkContext:
    def __init__(self, deadline, traceparent=None, parent=None):
        self.deadline, self.traceparent, self.parent = deadline, traceparent, parent

    @staticmethod
    def current():
        return _current.get()

    def check(self):
        if self.parent is not None:
            self.parent.check()
        self.deadline.check()

    def remaining_milliseconds(self):
        self.check()
        remaining = self.deadline.remaining_milliseconds()
        return remaining if self.parent is None else min(remaining, self.parent.remaining_milliseconds())


@contextmanager
def work_context(deadline, traceparent=None):
    parent = WorkContext.current()
    if traceparent is None and parent is not None:
        traceparent = parent.traceparent
    if traceparent is not None and parse_traceparent(traceparent) is None:
        raise ValueError('Invalid trace context.')
    context = WorkContext(deadline, traceparent, parent)
    token = _current.set(context)
    try:
        context.check()
        yield context
        context.check()
    finally:
        _current.reset(token)
