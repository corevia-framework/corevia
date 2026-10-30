import json
import threading
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from corevia.model_server import ModelServer, ModelService
from corevia.scopes import ExecutionScope


class FixtureModel:
    def __init__(self):
        self.closed = False
        self.iterator_closed = False

    def stream(self, messages, maximum):
        assert ExecutionScope.current() is not None
        try:
            yield "hello "
            yield "🌍"
        finally:
            self.iterator_closed = True

    def embed(self, texts):
        return [[1.0, float(len(text) + 1)] for text in texts]

    def shutdown(self):
        self.closed = True


class ModelTests(unittest.TestCase):
    def setUp(self):
        self.model = FixtureModel()
        self.service = ModelService(self.model, "t" * 64)
        self.server = ModelServer(("127.0.0.1", 0), self.service)
        self.thread = threading.Thread(target=self.server.serve_forever)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(5)
        self.assertFalse(self.thread.is_alive())
        self.assertTrue(self.model.closed)

    def request(self, path, data=None, token=True):
        headers = {"Content-Type": "application/json"}
        if token:
            headers["Authorization"] = "Bearer " + "t" * 64
        request = Request("http://127.0.0.1:" + str(self.server.server_port) + path, data=None if data is None else json.dumps(data).encode(), headers=headers)
        return urlopen(request, timeout=5)

    def test_stream_scope_and_cleanup(self):
        with self.request("/v1/chat/completions", {"model": "local", "messages": [{"role": "user", "content": "private prompt"}], "stream": True}) as response:
            data = response.read().decode()
            self.assertIn("🌍", data)
            self.assertTrue(data.endswith("data: [DONE]\n\n"))
            self.assertNotIn("private prompt", data)
        self.assertTrue(self.model.iterator_closed)

    def test_auth_bounds_and_embeddings(self):
        with self.assertRaises(HTTPError) as error:
            self.request("/health", token=False)
        self.assertEqual(error.exception.code, 401)
        with self.assertRaises(HTTPError) as error:
            self.request("/v1/chat/completions", {"model": "unregistered", "stream": True})
        self.assertEqual(error.exception.code, 422)
        with self.request("/v1/embeddings", {"model": "local", "input": ["text"]}) as response:
            self.assertEqual(json.load(response)["data"][0]["embedding"], [1.0, 5.0])
        self.service.slots.acquire()
        try:
            with self.assertRaises(HTTPError) as error:
                self.request("/v1/embeddings", {"model": "local", "input": ["text"]})
            self.assertEqual(error.exception.code, 503)
        finally:
            self.service.slots.release()

    def test_startup_failure_disposes_model(self):
        class Broken(FixtureModel):
            def startup(self):
                raise ValueError("fixture startup")
        model = Broken()
        with self.assertRaises(ValueError):
            ModelService(model, "t" * 64)
        self.assertTrue(model.closed)


if __name__ == "__main__":
    unittest.main()
