"""Loopback model host. Model factories are operator-selected, never request-selected."""
import argparse
import hmac
import json
import math
import os
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from .scopes import execution_scope


class ModelService:
    def __init__(self, model, token, name="local", concurrency=1):
        if not isinstance(token, str) or len(token) < 32 or not 1 <= concurrency <= 16:
            raise ValueError("Use a private token of at least 32 characters and bounded concurrency.")
        self.model, self.token, self.name = model, token, name
        self.slots = threading.BoundedSemaphore(concurrency)
        self.closed = False
        try:
            if hasattr(model, "startup"):
                model.startup()
        except BaseException:
            try:
                self.close()
            except BaseException:
                pass
            raise

    def close(self):
        if not self.closed:
            self.closed = True
            if hasattr(self.model, "shutdown"):
                self.model.shutdown()


class ModelServer(ThreadingHTTPServer):
    daemon_threads = False
    allow_reuse_address = True

    def __init__(self, address, service):
        if address[0] != "127.0.0.1":
            raise ValueError("Model hosts bind loopback; expose through an authenticated TLS proxy.")
        self.service = service
        super().__init__(address, ModelHandler)

    def server_close(self):
        try:
            super().server_close()
        finally:
            self.service.close()


class ModelHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_):
        pass  # No prompts, bearer credentials or request targets in access logs.

    def setup(self):
        super().setup()
        self.connection.settimeout(10)

    def reply(self, status, value):
        body = json.dumps(value, allow_nan=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def authorized(self):
        supplied = self.headers.get("Authorization", "")
        expected = "Bearer " + self.server.service.token
        if not hmac.compare_digest(supplied.encode(), expected.encode()):
            self.reply(401, {"error": {"code": "UNAUTHENTICATED"}})
            return False
        return True

    def do_GET(self):
        if self.authorized():
            self.reply(200 if self.path == "/health" else 404, {"ready": not self.server.service.closed} if self.path == "/health" else {"error": {"code": "NOT_FOUND"}})

    def do_POST(self):
        if not self.authorized():
            return
        if self.path not in {"/v1/chat/completions", "/v1/embeddings"}:
            self.reply(404, {"error": {"code": "NOT_FOUND"}})
            return
        service = self.server.service
        if not service.slots.acquire(blocking=False):
            self.reply(503, {"error": {"code": "MODEL_BUSY"}})
            return
        held, streaming = True, False

        def release():
            nonlocal held
            if held:
                service.slots.release()
                held = False

        try:
            if self.headers.get("Transfer-Encoding") or self.headers.get("Content-Type", "").split(";")[0] != "application/json":
                raise ValueError("Unsupported body framing.")
            if len(self.headers.get_all("Content-Length", [])) != 1:
                raise ValueError("Ambiguous body length.")
            size = int(self.headers.get("Content-Length", "0"))
            if not 1 <= size <= 65536:
                raise ValueError("Invalid body size.")
            raw = self.rfile.read(size)
            if len(raw) != size:
                raise ValueError("Incomplete body.")
            data = json.loads(raw, parse_constant=lambda _: (_ for _ in ()).throw(ValueError("Non-finite JSON")))
            if not isinstance(data, dict) or data.get("model") != service.name:
                raise ValueError("Invalid model.")
            payload = None
            with execution_scope("ai.inference", {"model": service.name}):
                if self.path == "/v1/embeddings":
                    texts = data.get("input")
                    if not isinstance(texts, list) or not 1 <= len(texts) <= 16 or any(not isinstance(t, str) or len(t.encode()) > 4096 for t in texts):
                        raise ValueError("Invalid embedding input.")
                    vectors = list(service.model.embed(texts))
                    if len(vectors) != len(texts) or any(not isinstance(v, (list, tuple)) or not 1 <= len(v) <= 4096 or any(isinstance(n, bool) or not isinstance(n, (int, float)) or not math.isfinite(n) for n in v) for v in vectors):
                        raise RuntimeError("Invalid model embedding output.")
                    payload = {"data": [{"index": i, "embedding": v} for i, v in enumerate(vectors)]}
                else:
                    messages = data.get("messages")
                    maximum = data.get("max_completion_tokens", data.get("max_tokens", 256))
                    if not isinstance(maximum, int) or isinstance(maximum, bool) or not 1 <= maximum <= 8192 or data.get("stream") is not True:
                        raise ValueError("Invalid inference options.")
                    if not isinstance(messages, list) or not 1 <= len(messages) <= 64 or any(not isinstance(m, dict) or set(m) != {"role", "content"} or m["role"] not in {"system", "user", "assistant"} or not isinstance(m["content"], str) for m in messages):
                        raise ValueError("Invalid messages.")
                    if sum(len(m["content"].encode()) for m in messages) > 65536:
                        raise ValueError("Context too large.")
                    self.send_response(200)
                    self.send_header("Content-Type", "text/event-stream")
                    self.send_header("Cache-Control", "no-store")
                    self.send_header("Connection", "close")
                    self.end_headers()
                    streaming = True
                    iterator = iter(service.model.stream(messages, maximum))
                    total, deadline = 0, time.monotonic() + 60
                    try:
                        for text in iterator:
                            if not isinstance(text, str):
                                raise RuntimeError("Invalid model delta.")
                            total += len(text.encode())
                            if total > 1048576 or time.monotonic() > deadline:
                                raise RuntimeError("Inference budget exceeded.")
                            self.wfile.write(("data: " + json.dumps({"choices": [{"delta": {"content": text}}]}, ensure_ascii=False) + "\n\n").encode())
                            self.wfile.flush()
                    finally:
                        if hasattr(iterator, "close"):
                            iterator.close()
            # Model work AND scope cleanup finish before success is visible to clients.
            # A slow terminal network write must not retain an inference slot.
            release()
            if streaming:
                self.wfile.write(b"data: [DONE]\n\n")
                self.wfile.flush()
            else:
                self.reply(200, payload)
        except (BrokenPipeError, ConnectionResetError, TimeoutError):
            pass
        except Exception as error:
            release()
            if streaming:
                try:
                    self.wfile.write(b'data: {"error":{"code":"INFERENCE_FAILED"}}\n\n')
                    self.wfile.flush()
                except OSError:
                    pass
            else:
                self.reply(422 if isinstance(error, (ValueError, UnicodeError)) else 500, {"error": {"code": "INVALID_INPUT" if isinstance(error, (ValueError, UnicodeError)) else "INFERENCE_FAILED"}})
        finally:
            self.close_connection = True
            release()

class DemoModel:
    """Explicit echo fixture for transport learning; not an LLM."""
    def stream(self, messages, maximum):
        yield "Demo echo: "
        yield messages[-1]["content"][:maximum]

    def embed(self, texts):
        return [[float(len(text) + 1), 1.0] for text in texts]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--demo", action="store_true")
    parser.add_argument("--port", type=int, default=8111)
    parser.add_argument("--model", default="local")
    args = parser.parse_args()
    if args.demo:
        if os.environ.get("APP_ENV") == "production":
            parser.error("Demo mode is development-only.")
        model = DemoModel()
    else:
        parser.error("Use tools/model-host.py for an operator-controlled model factory, or explicit --demo.")
    service = ModelService(model, os.environ.get("MODEL_WORKER_TOKEN", ""), args.model)
    try:
        server = ModelServer(("127.0.0.1", args.port), service)
    except BaseException:
        service.close()
        raise
    print(json.dumps({"ready": True, "port": server.server_port}), flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
