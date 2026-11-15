"""Bounded explicit instrumentation with no request/prompt/credential attributes."""
import re
import time
import uuid
from contextlib import contextmanager
from .work_context import Deadline, WorkContext, child_traceparent, work_context


class Telemetry:
    def __init__(self, service, operations, capacity=256):
        if not isinstance(service, str) or re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', service) is None or type(capacity) is not int or not 1 <= capacity <= 1024 or len(operations) > 128:
            raise ValueError('Invalid telemetry bounds.')
        for name, kind in operations.items():
            if re.fullmatch(r'[A-Za-z][A-Za-z0-9_.:-]{0,95}', name) is None or kind not in {'internal','server','client','producer','consumer'}:
                raise ValueError('Register static operations.')
        self.service, self.operations, self.capacity = service, dict(operations), capacity
        self._spans, self._dropped = [], 0
        # Worker requests run in threads. Lock only bounded recording/drain, never user work.
        import threading
        self._lock = threading.Lock()

    @contextmanager
    def span(self, operation):
        if operation not in self.operations:
            raise ValueError('Register this telemetry operation first.')
        parent = WorkContext.current()
        incoming = parent.traceparent if parent else None
        trace = child_traceparent(incoming) if incoming else '00-' + uuid.uuid4().hex + '-' + uuid.uuid4().hex[:16] + '-01'
        start, mono, failure = time.time_ns(), time.monotonic_ns(), None
        try:
            remaining = parent.remaining_milliseconds() if parent else 3600000
            with work_context(Deadline(remaining), trace):
                yield trace
        except BaseException as error:
            failure = type(error).__module__ + '.' + type(error).__name__
            raise
        finally:
            span = {'traceId':trace[3:35], 'spanId':trace[36:52], 'name':operation,
                    'kind':['unspecified','internal','server','client','producer','consumer'].index(self.operations[operation]),
                    'startTimeUnixNano':str(start), 'endTimeUnixNano':str(start + max(0,time.monotonic_ns()-mono)),
                    'status':{'code':2 if failure else 1}}
            if incoming:
                span['parentSpanId'] = incoming[36:52]
            if failure:
                span['attributes'] = [{'key':'error.type','value':{'stringValue':failure}}]
            if trace[-2:] == '01':
                with self._lock:
                    if len(self._spans) < self.capacity:
                        self._spans.append(span)
                    else:
                        self._dropped += 1

    def drain(self):
        with self._lock:
            spans, dropped = self._spans, self._dropped
            self._spans, self._dropped = [], 0
        return {'resourceSpans':[{'resource':{'attributes':[{'key':'service.name','value':{'stringValue':self.service}}]},
                'scopeSpans':[{'scope':{'name':'corevia.python'},'spans':spans}]}]}, dropped
