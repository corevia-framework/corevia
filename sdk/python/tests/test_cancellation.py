import threading
import unittest
from corevia import CancellationRegistry, Deadline, WorkBudgetError, Worker
from corevia.cancellation import CancellationError


class CancellationTests(unittest.TestCase):
    def test_registration_ownership_and_bounds(self):
        registry = CancellationRegistry(1)
        deadline = Deadline(10000)
        first = registry.register('call', 'a' * 64, 'work', deadline)
        with self.assertRaises(CancellationError):
            registry.register('call', 'b' * 64, 'work', Deadline(10000))
        with self.assertRaises(CancellationError):
            registry.register('other', 'b' * 64, 'work', Deadline(10000))
        registry.signal('call', 'b' * 64, lambda target: True)
        registry.signal('call', 'a' * 64, lambda target: False)
        deadline.check()
        threads = [threading.Thread(target=registry.signal, args=('call', 'a' * 64, lambda target: True)) for _ in range(8)]
        for thread in threads: thread.start()
        for thread in threads: thread.join()
        with self.assertRaisesRegex(WorkBudgetError, 'WORK_CANCELLED'):
            deadline.check()
        registry.release('call', first)
        second_deadline = Deadline(10000)
        second = registry.register('call', 'c' * 64, 'work', second_deadline)
        registry.release('call', first)
        registry.signal('call', 'a' * 64, lambda target: True)
        second_deadline.check()
        self.assertEqual(registry.active_count(), 1)
        registry.release('call', second)
        self.assertEqual(registry.active_count(), 0)
        third_deadline = Deadline(10000)
        third = registry.register('last', 'd' * 64, 'work', third_deadline)
        registry.close()
        with self.assertRaises(WorkBudgetError): third_deadline.check()
        with self.assertRaises(CancellationError): registry.register('new', 'e' * 64, 'work', Deadline(10000))
        registry.release('last', third)
        self.assertEqual(registry.active_count(), 0)

    def test_worker_opt_in_current_authority_and_no_existence_disclosure(self):
        with self.assertRaises(ValueError):
            Worker(cancellation=CancellationRegistry())
        permitted = {'corevia.cancel', 'work'}
        worker = Worker(authorize=lambda authorization, target: authorization == 'Bearer fixture' and target in permitted, cancellation=CancellationRegistry())
        deadline = Deadline(10000)
        entry = worker.cancellation.register('id', 'a' * 64, 'work', deadline)
        from corevia import CoreviaError
        with self.assertRaises(CoreviaError):
            worker.cancel({'request_id':'id','capability':'a' * 64}, 'Bearer wrong')
        permitted.remove('work')
        self.assertEqual(worker.cancel({'request_id':'id','capability':'a' * 64}, 'Bearer fixture'), {'accepted':True})
        deadline.check()
        for request_id in ['unknown', 'id']:
            self.assertEqual(worker.cancel({'request_id':request_id,'capability':'b' * 64}, 'Bearer fixture'), {'accepted':True})
        permitted.add('work')
        worker.cancel({'request_id':'id','capability':'a' * 64}, 'Bearer fixture')
        with self.assertRaises(WorkBudgetError): deadline.check()
        worker.cancellation.release('id', entry)
        for value in [{}, {'request_id':'id','capability':'a' * 64,'extra':True}, {'request_id':'id','capability':'private'}]:
            with self.assertRaises(ValueError): worker.cancel(value, 'Bearer fixture')

    def test_dispatch_failure_and_cleanup_release_registration(self):
        worker = Worker(token='fixture', cancellation=CancellationRegistry())
        closed = []
        worker.on_scope(lambda scope: scope.on_close(lambda: closed.append(True)))
        @worker.action('work')
        def work(payload):
            worker.cancel({'request_id':'id','capability':'a' * 64}, 'Bearer fixture')
            return {'should_not_succeed':True}
        envelope = {'cvp':'2.0','id':'id','type':'action.call','target':'work','payload':{},'meta':{'timeout_ms':1000,'cancel_capability':'a' * 64}}
        result = worker.dispatch(envelope, 'Bearer fixture')
        self.assertEqual(result['error']['code'], 'WORK_CANCELLED')
        self.assertFalse(result['error']['retryable'])
        self.assertEqual(closed, [True])
        self.assertEqual(worker.cancellation.active_count(), 0)
        result = worker.dispatch(envelope, 'Bearer wrong')
        self.assertEqual(result['error']['code'], 'UNAUTHORIZED')
        self.assertEqual(closed, [True])
