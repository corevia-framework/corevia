import json
import threading
import time
import unittest
from pathlib import Path
from corevia import Client, CoreviaError, Worker, validate_envelope


class RuntimeTests(unittest.TestCase):
    def test_shared_fixtures(self):
        fixtures = Path(__file__).resolve().parents[3] / 'protocol' / 'fixtures'
        for value in json.loads((fixtures / 'valid.json').read_text()):
            self.assertIs(validate_envelope(value), value)
        for value in json.loads((fixtures / 'invalid.json').read_text()):
            with self.assertRaises(CoreviaError):
                validate_envelope(value)

    def test_dispatch_auth_trace_errors(self):
        worker = Worker(token='secret')

        @worker.action('echo')
        def echo(payload):
            return payload

        @worker.action('fail')
        def fail(payload):
            raise CoreviaError('EXPECTED', 'Expected error', True)

        envelope = {'cvp':'1.0','id':'req_test','type':'action.call','target':'echo','payload':{'a':1},'meta':{'trace_id':'trace_test'}}
        self.assertEqual(worker.dispatch(envelope)['error']['code'], 'UNAUTHORIZED')
        result = worker.dispatch(envelope, 'Bearer secret')
        self.assertEqual(result['result'], {'a':1})
        self.assertEqual(result['meta']['trace_id'], 'trace_test')
        envelope['target'] = 'fail'
        self.assertTrue(worker.dispatch(envelope, 'Bearer secret')['error']['retryable'])
        envelope['target'] = 'absent'
        self.assertEqual(worker.dispatch(envelope, 'Bearer secret')['error']['code'], 'ACTION_NOT_FOUND')
        with self.assertRaises(ValueError):
            worker.action('echo')(echo)

    def test_public_bind_requires_auth(self):
        with self.assertRaises(ValueError):
            Worker('http://0.0.0.0:8090')

    def test_http_client_worker_roundtrip(self):
        worker = Worker(token='test-secret')
        worker.port = 0
        worker.action('echo')(lambda payload: payload)
        thread = threading.Thread(target=worker.run, daemon=True)
        thread.start()
        for _ in range(100):
            if worker.server is not None:
                break
            time.sleep(.01)
        self.assertIsNotNone(worker.server)
        url = 'http://127.0.0.1:' + str(worker.server.server_port)
        try:
            value = Client(url, token='test-secret').call('echo', {'value':42}, trace_id='trace_http')
            self.assertEqual(value['result'], {'value':42})
            self.assertEqual(value['meta']['trace_id'], 'trace_http')
            with self.assertRaises(CoreviaError) as failure:
                Client(url).call('echo', {})
            self.assertEqual(failure.exception.code, 'UNAUTHORIZED')
        finally:
            worker.stop()
            thread.join(2)
        self.assertFalse(thread.is_alive())

    def test_non_finite_payload_rejected(self):
        with self.assertRaises(CoreviaError):
            validate_envelope({'cvp':'1.0','id':'x','type':'ping','payload':{'n':float('nan')}})


if __name__ == '__main__':
    unittest.main()
