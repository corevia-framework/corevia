import threading
import unittest
from corevia import Deadline, WorkContext, WorkBudgetError, Worker, work_context
from corevia.work_context import parse_traceparent


class WorkContextTests(unittest.TestCase):
    def test_nested_limits_and_cancellation(self):
        tick = [0]
        outer = Deadline(10, lambda: tick[0])
        with self.assertRaisesRegex(WorkBudgetError, 'WORK_CANCELLED'):
            with work_context(outer):
                with work_context(Deadline(1000, lambda: tick[0])) as inner:
                    self.assertEqual(inner.remaining_milliseconds(), 10)
                    outer.cancel()
                    inner.check()
        self.assertIsNone(WorkContext.current())

    def test_threads_and_cleanup(self):
        values = []
        with work_context(Deadline(1000)):
            thread = threading.Thread(target=lambda: values.append(WorkContext.current()))
            thread.start()
            thread.join()
        self.assertEqual(values, [None])
        self.assertIsNone(WorkContext.current())

    def test_worker_budget_and_trace(self):
        worker = Worker()
        closed = []
        @worker.on_scope
        def scope_hook(scope):
            scope.on_close(lambda: closed.append(True))
        @worker.action('inspect')
        def inspect(payload):
            context = WorkContext.current()
            if payload.get('cancel'):
                context.deadline.cancel()
            return {'budget': context.remaining_milliseconds(), 'traceparent': context.traceparent}
        trace = '00-' + 'a' * 32 + '-' + 'b' * 16 + '-01'
        request = {'cvp':'2.0', 'id':'request', 'type':'action.call', 'target':'inspect', 'payload':{}, 'meta':{'timeout_ms':1000, 'traceparent':trace}}
        result = worker.dispatch(request)
        self.assertTrue(result['ok'])
        self.assertLessEqual(result['result']['budget'], 1000)
        self.assertEqual(result['result']['traceparent'][3:35], 'a' * 32)
        self.assertNotEqual(result['result']['traceparent'], trace)
        request['payload'] = {'cancel':True}
        result = worker.dispatch(request)
        self.assertFalse(result['ok'])
        self.assertEqual(result['error']['code'], 'WORK_CANCELLED')
        self.assertFalse(result['error']['retryable'])
        self.assertEqual(closed, [True, True])
        self.assertIsNone(WorkContext.current())

    def test_trace_negatives(self):
        for value in ['', '00-' + '0' * 32 + '-' + 'a' * 16 + '-01', '00-' + 'a' * 32 + '-' + '0' * 16 + '-01', '01-' + 'a' * 32 + '-' + 'b' * 16 + '-01', {}]:
            self.assertIsNone(parse_traceparent(value))


if __name__ == '__main__':
    unittest.main()
