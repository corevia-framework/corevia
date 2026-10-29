import concurrent.futures
import threading
import unittest
from corevia import Client, ExecutionScope, Worker, execution_scope

class ScopeTests(unittest.TestCase):
    def test_scope_hook_failure_is_correlated_and_private(self):
        worker = Worker()
        closed = []
        def prepare(scope):
            scope.service('cache',dict,lambda cache: closed.append(True))
            self.assertIs(worker.service('cache'),scope.service('cache'))
            raise RuntimeError('private scope hook details')
        worker.on_scope(prepare)
        result = worker.dispatch({'cvp':'2.0','id':'failure','type':'action.call','target':'echo','payload':{},'meta':{'trace_id':'trace','parent_trace_id':'parent'}})
        self.assertEqual(result['error']['code'],'SCOPE_EXECUTION_FAILED')
        self.assertEqual(result['meta']['trace_id'],'trace')
        self.assertEqual(result['meta']['parent_trace_id'],'parent')
        self.assertNotIn('private',str(result))
        self.assertEqual(closed,[True])

    def test_shutdown_waits_for_dispatch_cleanup(self):
        worker = Worker()
        worker.port = 0
        started, release, ready = threading.Event(), threading.Event(), threading.Event()
        order = []
        worker.on_startup(lambda worker: ready.set())
        worker.on_shutdown(lambda worker: order.append('process'))
        @worker.action('echo')
        def echo(payload):
            ExecutionScope.current().on_close(lambda: order.append('request'))
            started.set()
            if not release.wait(3):
                raise RuntimeError('Test request was not released.')
            return payload
        thread = threading.Thread(target=worker.run,daemon=True)
        thread.start()
        self.assertTrue(ready.wait(3))
        try:
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                reply = pool.submit(Client('http://127.0.0.1:'+str(worker.server.server_port)).call,'echo',{})
                self.assertTrue(started.wait(3))
                worker.stop()
                self.assertNotIn('process',order)
                release.set()
                self.assertTrue(reply.result(timeout=3)['ok'])
        finally:
            release.set()
            worker.stop()
            thread.join(3)
        self.assertFalse(thread.is_alive())
        self.assertEqual(order,['request','process'])

    def test_partial_startup_unwinds_process_resources(self):
        worker = Worker()
        worker.port = 0
        order = []
        def bad_start(worker):
            raise ValueError('startup failed')
        worker.on_startup(bad_start)
        worker.on_shutdown(lambda worker: order.append('first'))
        def bad_close(worker):
            order.append('second')
            raise RuntimeError('private details')
        worker.on_shutdown(bad_close)
        with self.assertRaisesRegex(ValueError, 'startup failed'):
            worker.run()
        self.assertEqual(order, ['second', 'first'])

    def test_reverse_cleanup_and_original_failure(self):
        order = []
        with self.assertRaisesRegex(ValueError, 'original'):
            with execution_scope('python') as scope:
                scope.on_close(lambda: order.append('first'))
                def bad():
                    order.append('second')
                    raise RuntimeError('private')
                scope.on_close(bad)
                raise ValueError('original')
        self.assertEqual(order, ['second', 'first'])
        self.assertIsNone(ExecutionScope.current())

    def test_thread_isolation_and_worker_cleanup(self):
        worker = Worker(token='test-only')
        barrier = threading.Barrier(2)
        closed = []
        @worker.action('echo')
        def echo(payload):
            value = worker.service('request', object, lambda item: closed.append(item))
            self.assertIs(value, worker.service('request', object))
            scope_id = ExecutionScope.current().id
            barrier.wait(timeout=3)
            return {'scope':scope_id}
        def call(index):
            request = {'cvp':'2.0','id':str(index),'type':'action.call','target':'echo','payload':{},'meta':{}}
            result = worker.dispatch(request, 'Bearer test-only')
            self.assertIsNone(ExecutionScope.current())
            return result['result']['scope']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            ids = list(pool.map(call, [1, 2]))
        self.assertNotEqual(ids[0], ids[1])
        self.assertEqual(len(closed), 2)
