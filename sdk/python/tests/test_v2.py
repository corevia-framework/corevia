import json
import unittest
from pathlib import Path
from corevia.runtime import Worker, CoreviaError, validate_envelope

class V2Tests(unittest.TestCase):
    def test_shared_fixtures(self):
        fixtures = json.loads((Path(__file__).resolve().parents[3] / 'protocol/v2/fixtures.json').read_text())
        for value in fixtures['valid']:
            validate_envelope(value)
        for value in fixtures['invalid']:
            with self.assertRaises(CoreviaError):
                validate_envelope(value)

    def test_worker_version_auth_and_parent_trace(self):
        worker = Worker(token='test-token')
        @worker.action('echo')
        def echo(payload):
            return payload
        request = {'cvp':'2.0','id':'req_1','type':'action.call','target':'echo','payload':{'id':42},'meta':{'trace_id':'trace','parent_trace_id':'parent','timeout_ms':5000}}
        denied = worker.dispatch(request)
        self.assertEqual(denied['error']['code'], 'UNAUTHORIZED')
        result = worker.dispatch(request, 'Bearer test-token')
        validate_envelope(result)
        self.assertEqual(result['cvp'], '2.0')
        self.assertEqual(result['meta']['parent_trace_id'], 'parent')
        self.assertEqual(result['result'], {'id':42})
