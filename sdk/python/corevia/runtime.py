import hmac
import json
import logging
import math
import socket
import time
import uuid
from functools import partial
from contextlib import nullcontext
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, build_opener, HTTPRedirectHandler
from .scopes import ExecutionScope, execution_scope
from .work_context import Deadline, WorkContext, WorkBudgetError, work_context, parse_traceparent, child_traceparent
from .cancellation import CancellationRegistry, CancellationError, validate_control

class _NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        return None


TYPES = {"action.call", "action.result", "event", "subscribe", "unsubscribe", "ping", "pong"}


class CoreviaError(Exception):
    def __init__(self, code, message, retryable=False, request_id=None, trace_id=None):
        super().__init__(message)
        self.code, self.retryable = code, retryable
        self.request_id, self.trace_id = request_id, trace_id


def validate_envelope(value):
    if not isinstance(value, dict) or value.get("cvp") not in {"1.0", "2.0"} or not isinstance(value.get("id"), str) or not 0 < len(value["id"]) <= 128:
        raise CoreviaError("INVALID_ENVELOPE", "Invalid CVP/1 envelope.")
    meta = value.get("meta", {})
    if not isinstance(meta, dict) or (meta.get("trace_id") is not None and (not isinstance(meta["trace_id"], str) or len(meta["trace_id"]) > 128)):
        raise CoreviaError("INVALID_META", "Invalid metadata.")
    if value["cvp"] == "2.0":
        if 'cancel_capability' in meta:
            try:
                validate_control(value['id'], meta['cancel_capability'])
            except ValueError:
                raise CoreviaError('INVALID_META', 'Invalid cancellation metadata.')
        for key in ("trace_id", "parent_trace_id"):
            if meta.get(key) is not None and (not isinstance(meta[key], str) or not 0 < len(meta[key]) <= 128):
                raise CoreviaError("INVALID_META", "Invalid trace metadata.")
        if "traceparent" in meta and parse_traceparent(meta["traceparent"]) is None:
            raise CoreviaError("INVALID_META", "Invalid trace context.")
        deadline = meta.get("deadline_unix_ms")
        if deadline is not None and (type(deadline) is not int or not 1 <= deadline <= 253402300799999):
            raise CoreviaError("INVALID_TIMEOUT", "Invalid deadline.")
        timeout = meta.get("timeout_ms", 5000)
        if type(timeout) is not int or not 1 <= timeout <= 300000:
            raise CoreviaError("INVALID_TIMEOUT", "Invalid timeout.")
        if "ok" in value and value.get("type") != "action.result":
            raise CoreviaError("INVALID_MESSAGE_TYPE", "Invalid response type.")
    if "ok" in value:
        error = value.get("error")
        if not isinstance(value["ok"], bool) or "result" not in value or "error" not in value or (value["ok"] and error is not None) or (not value["ok"] and (not isinstance(error, dict) or not isinstance(error.get("code"), str) or not isinstance(error.get("message"), str) or not isinstance(error.get("retryable"), bool))):
            raise CoreviaError("INVALID_RESPONSE", "Invalid CVP response.")
    elif value.get("type") not in TYPES or not isinstance(value.get("payload"), (dict, list)) or (value["type"] in {"action.call", "event", "subscribe", "unsubscribe"} and (not isinstance(value.get("target"), str) or not 0 < len(value["target"]) <= 128)):
        raise CoreviaError("INVALID_ENVELOPE", "Invalid CVP message.")
    # Python's JSON decoder accepts NaN by default; CVP never does.
    def safe(item, depth=0):
        if depth > 60 or not isinstance(item, (dict, list, str, int, float, bool, type(None))) or (isinstance(item, float) and not math.isfinite(item)):
            raise CoreviaError("NON_JSON_VALUE", "JSON-compatible values required.")
        if isinstance(item, (dict, list)):
            for child in item.values() if isinstance(item, dict) else item:
                safe(child, depth + 1)
    safe(value)
    return value


def response(request_id, result=None, error=None, trace_id=None, version="1.0", parent_trace_id=None):
    value = {"cvp": version, "id": request_id, "ok": error is None, "result": result, "error": error, "meta": {"trace_id": trace_id}}
    if version == "2.0":
        value["type"] = "action.result"
        if parent_trace_id is not None:
            value["meta"]["parent_trace_id"] = parent_trace_id
    return value


class Worker:
    def __init__(self, url="http://127.0.0.1:8090", token=None, authorize=None, timeout=5.0, max_bytes=65536, telemetry=None, cancellation=None):
        parsed = urlparse(url)
        if parsed.scheme != "http" or not parsed.hostname or timeout <= 0:
            raise ValueError("Worker requires an HTTP bind URL and positive timeout; terminate TLS at a proxy.")
        if parsed.hostname not in {"127.0.0.1", "localhost", "::1"} and token is None and authorize is None:
            raise ValueError("Public workers require authorization.")
        self.host, self.port = parsed.hostname, parsed.port if parsed.port is not None else 8090
        self.token, self.authorize = token, authorize
        self.timeout, self.max_bytes = timeout, max_bytes
        self.actions = {}
        if cancellation is not None and (not isinstance(cancellation, CancellationRegistry) or (not token and authorize is None)):
            raise ValueError('Cancellation requires an explicit registry and worker authorization.')
        self.cancellation = cancellation
        self.telemetry = telemetry
        self.server = None
        self.scope_hooks = []
        self.startup_hooks = []
        self.shutdown_hooks = []

    def on_startup(self, callback):
        self.startup_hooks.append(callback)
        return callback

    def on_shutdown(self, callback):
        self.shutdown_hooks.append(callback)
        return callback

    def on_scope(self, callback):
        self.scope_hooks.append(callback)
        return callback

    def service(self, name, factory=None, cleanup=None):
        scope = ExecutionScope.current()
        if scope is None:
            raise RuntimeError('Worker services require an execution scope.')
        return scope.service(name, factory, cleanup)

    def action(self, name):
        def register(handler):
            if name in self.actions:
                raise ValueError("Duplicate action: " + name)
            self.actions[name] = handler
            return handler
        return register

    def dispatch(self, envelope, authorization=None):
        validate_envelope(envelope)
        result = None
        active = None
        try:
            timeout_ms = min(300000, max(1, int(self.timeout * 1000)), envelope.get('meta', {}).get('timeout_ms', 5000)) if envelope['cvp'] == '2.0' else min(300000, max(1, int(self.timeout * 1000)))
            if envelope['cvp'] == '2.0' and 'deadline_unix_ms' in envelope.get('meta', {}):
                timeout_ms = min(timeout_ms, envelope['meta']['deadline_unix_ms'] - time.time_ns() // 1000000)
                if timeout_ms < 1:
                    raise WorkBudgetError('DEADLINE_EXCEEDED')
            incoming = envelope.get('meta', {}).get('traceparent') if envelope['cvp'] == '2.0' else None
            traceparent = (incoming if self.telemetry is not None else child_traceparent(incoming)) if incoming else None
            deadline = Deadline(timeout_ms)
            capability = envelope.get('meta', {}).get('cancel_capability')
            if capability is not None:
                if envelope['cvp'] != '2.0' or self.cancellation is None:
                    raise CancellationError('CANCELLATION_UNAVAILABLE')
                if not self._permitted(authorization, envelope.get('target')):
                    return response(envelope['id'], error={'code':'UNAUTHORIZED','message':'Unauthorized.','retryable':False}, version=envelope['cvp'])
                active = self.cancellation.register(envelope['id'], capability, envelope['target'], deadline)
            with (work_context(deadline, traceparent) if envelope['cvp'] == '2.0' else nullcontext()), execution_scope('python') as scope, (self.telemetry.span('python.action') if self.telemetry is not None else nullcontext()):
                for hook in self.scope_hooks:
                    hook(scope)
                result = self._dispatch(envelope, authorization)
                if self.telemetry is not None and not result['ok']:
                    raise RuntimeError('ACTION_FAILED')  # Record failure without exporting its message or data.
                return result
        except WorkBudgetError as error:
            return response(envelope['id'], error={'code': error.code, 'message': 'Worker budget ended.', 'retryable': False}, version=envelope['cvp'], trace_id=envelope.get('meta', {}).get('trace_id'))
        except CancellationError as error:
            return response(envelope['id'], error={'code':error.code,'message':'Cancellation registration unavailable.','retryable':False}, version=envelope['cvp'])
        except Exception as error:
            if result is not None and not result['ok']:
                return result
            try:
                logging.getLogger('corevia').error('Worker scope failed exception=%s', type(error).__name__)
            except Exception:
                pass
            code = 'SCOPE_CLEANUP_FAILED' if str(error) == 'SCOPE_CLEANUP_FAILED' else 'SCOPE_EXECUTION_FAILED'
            return response(envelope['id'], error={'code':code, 'message':'Worker scope failed.', 'retryable':False}, version=envelope['cvp'], trace_id=envelope.get('meta', {}).get('trace_id'), parent_trace_id=envelope.get('meta', {}).get('parent_trace_id'))
        finally:
            if active is not None:
                self.cancellation.release(envelope['id'], active)

    def _permitted(self, authorization, target):
        try:
            return bool(self.authorize(authorization, target)) if self.authorize else (self.token is None or hmac.compare_digest(authorization or '', 'Bearer ' + self.token))
        except Exception:
            logging.getLogger('corevia').error('Worker authorization failed.')
            return False

    def cancel(self, value, authorization=None):
        if self.cancellation is None:
            raise CoreviaError('NOT_FOUND', 'Endpoint not found.')
        if not self._permitted(authorization, 'corevia.cancel'):
            raise CoreviaError('UNAUTHORIZED', 'Unauthorized.')
        if not isinstance(value, dict) or set(value) != {'request_id', 'capability'}:
            raise ValueError('Invalid cancellation control.')
        validate_control(value['request_id'], value['capability'])
        self.cancellation.signal(value['request_id'], value['capability'], lambda target:self._permitted(authorization, target))
        # Do not disclose whether a matching live call exists, is authorized or has stopped.
        return {'accepted': True}

    def _dispatch(self, envelope, authorization=None):
        validate_envelope(envelope)
        trace = envelope.get("meta", {}).get("trace_id")
        target = envelope.get("target")
        reply = partial(response, version=envelope["cvp"], parent_trace_id=envelope.get("meta", {}).get("parent_trace_id"))
        permitted = self._permitted(authorization, target)
        if not permitted:
            return reply(envelope["id"], error={"code": "UNAUTHORIZED", "message": "Unauthorized.", "retryable": False}, trace_id=trace)
        if envelope.get("type") != "action.call" or target not in self.actions:
            return reply(envelope["id"], error={"code": "ACTION_NOT_FOUND", "message": "Action not registered.", "retryable": False}, trace_id=trace)
        try:
            context = WorkContext.current()
            if context is not None:
                context.check()
            result = self.actions[target](envelope["payload"])
            if context is not None:
                context.check()
            json.dumps(result, allow_nan=False)
            return reply(envelope["id"], result, trace_id=trace)
        except WorkBudgetError as error:
            return reply(envelope["id"], error={"code": error.code, "message": "Worker budget ended.", "retryable": False}, trace_id=trace)
        except CoreviaError as error:
            return reply(envelope["id"], error={"code": error.code, "message": str(error), "retryable": error.retryable}, trace_id=trace)
        except Exception as error:
            logging.getLogger("corevia").error("Action failed target=%s trace_id=%s exception=%s", target, trace, type(error).__name__)
            return reply(envelope["id"], error={"code": "ACTION_EXCEPTION", "message": "Worker action failed.", "retryable": False}, trace_id=trace)

    def run(self):
        worker = self

        class Handler(BaseHTTPRequestHandler):
            def setup(self):
                super().setup()
                self.connection.settimeout(worker.timeout)

            def do_POST(self):
                if self.path == '/cvp/cancel' and worker.cancellation is not None:
                    self.cancel_call()
                    return
                request_id, trace, version = "invalid", None, "1.0"
                try:
                    if self.path != "/cvp":
                        raise CoreviaError("NOT_FOUND", "Endpoint not found.")
                    length = int(self.headers.get("Content-Length", "0"))
                    if self.headers.get("Transfer-Encoding") or not 0 < length <= worker.max_bytes:
                        raise CoreviaError("PAYLOAD_TOO_LARGE", "Invalid body size.")
                    raw = self.rfile.read(length)
                    if len(raw) != length:
                        raise CoreviaError("INVALID_REQUEST", "Incomplete request.")
                    envelope = json.loads(raw)
                    validate_envelope(envelope)
                    request_id, trace = envelope["id"], envelope.get("meta", {}).get("trace_id")
                    version = envelope["cvp"]
                    value = worker.dispatch(envelope, self.headers.get("Authorization"))
                    status = 200 if value["ok"] else (401 if value["error"]["code"] == "UNAUTHORIZED" else 422)
                except (ValueError, CoreviaError, socket.timeout, RecursionError) as error:
                    value = response(request_id, error={"code": getattr(error, "code", "INVALID_REQUEST"), "message": "Invalid worker request.", "retryable": False}, trace_id=trace, version=version)
                    status = 400
                wire = json.dumps(value, allow_nan=False).encode()
                if len(wire) > worker.max_bytes:
                    wire = json.dumps(response(request_id, error={"code": "PAYLOAD_TOO_LARGE", "message": "Worker result too large.", "retryable": False}, trace_id=trace, version=version)).encode()
                    status = 413
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(wire)))
                self.end_headers()
                self.wfile.write(wire)

            def cancel_call(self):
                try:
                    length = int(self.headers.get('Content-Length', '0'))
                    if self.headers.get('Transfer-Encoding') or not 0 < length <= 1024:
                        raise ValueError('Invalid control size.')
                    raw = self.rfile.read(length)
                    if len(raw) != length:
                        raise ValueError('Incomplete control.')
                    value = worker.cancel(json.loads(raw), self.headers.get('Authorization'))
                    status = 202
                except (ValueError, CoreviaError, socket.timeout, RecursionError) as error:
                    status = 401 if getattr(error, 'code', None) == 'UNAUTHORIZED' else 400
                    value = {'accepted': False}
                wire = json.dumps(value).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(wire)))
                self.end_headers()
                self.wfile.write(wire)

            def log_message(self, format, *args):
                logging.getLogger("corevia.http").info("HTTP status %s", args[1] if len(args) > 1 else "unknown")

        self.server = ThreadingHTTPServer((self.host, self.port), Handler)
        # Drain request threads before releasing shared process resources.
        self.server.daemon_threads = False
        failed = False
        try:
            for hook in self.startup_hooks:
                hook(self)
            self.server.serve_forever(poll_interval=0.2)
        except BaseException:
            failed = True
            raise
        finally:
            if self.cancellation is not None:
                self.cancellation.close()
            self.server.server_close()
            failures = []
            for hook in reversed(self.shutdown_hooks):
                try:
                    hook(self)
                except BaseException as error:
                    failures.append(type(error).__name__)
            if failures:
                try:
                    logging.getLogger('corevia').error('Worker shutdown failed classes=%s', ','.join(failures))
                except Exception:
                    pass
                if not failed:
                    raise RuntimeError('WORKER_SHUTDOWN_FAILED')

    def stop(self):
        if self.cancellation is not None:
            self.cancellation.close()
        if self.server:
            self.server.shutdown()


class Client:
    def __init__(self, base_url, token=None, timeout=5.0, max_bytes=65536, version="1.0"):
        if timeout <= 0 or urlparse(base_url).scheme not in {"http", "https"}:
            raise ValueError("HTTP(S) URL and positive timeout required.")
        self.base_url, self.token, self.timeout, self.max_bytes = base_url.rstrip("/"), token, timeout, max_bytes
        if version not in {"1.0", "2.0"}:
            raise ValueError("Unsupported protocol version.")
        self.version = version

    def call(self, target, payload, trace_id=None, request_id=None, parent_trace_id=None):
        context = WorkContext.current()
        if context is not None:
            context.check()
        timeout = self.timeout if context is None else min(self.timeout, context.remaining_milliseconds() / 1000)
        request_id, trace_id = request_id or str(uuid.uuid4()), trace_id or (context.traceparent[3:35] if context is not None and context.traceparent else str(uuid.uuid4()))
        envelope = {"cvp": self.version, "id": request_id, "type": "action.call", "target": target, "payload": payload, "meta": {"trace_id": trace_id}}
        if self.version == "2.0":
            envelope["meta"]["timeout_ms"] = max(1, int(timeout * 1000))
            if context is not None:
                envelope["meta"]["deadline_unix_ms"] = time.time_ns() // 1000000 + max(1, int(timeout * 1000))
            if context is not None and context.traceparent:
                envelope["meta"]["traceparent"] = context.traceparent
            if parent_trace_id is not None:
                envelope["meta"]["parent_trace_id"] = parent_trace_id
        validate_envelope(envelope)
        wire = json.dumps(envelope, allow_nan=False).encode()
        if len(wire) > self.max_bytes:
            raise CoreviaError("PAYLOAD_TOO_LARGE", "Request too large.")
        headers = {"Content-Type": "application/json", "X-Trace-Id": trace_id, "X-Request-Id": request_id}
        if context is not None and context.traceparent:
            headers["traceparent"] = context.traceparent
        token = self.token() if callable(self.token) else self.token
        if token:
            headers["Authorization"] = "Bearer " + token
        if context is not None:
            timeout = min(timeout, context.remaining_milliseconds() / 1000)
        end = time.monotonic() + timeout
        try:
            try:
                stream = build_opener(_NoRedirect()).open(Request(self.base_url + "/cvp", wire, headers), timeout=timeout)
            except HTTPError as error:
                stream = error
            with stream:
                status = stream.status
                response_stream = stream.fp if isinstance(stream, HTTPError) else stream
                raw = bytearray()
                while len(raw) <= self.max_bytes:
                    if context is not None:
                        context.check()
                    remaining = end - time.monotonic()
                    if context is not None:
                        remaining = min(remaining, context.remaining_milliseconds() / 1000)
                    if remaining <= 0:
                        raise CoreviaError('REMOTE_TIMEOUT', 'Remote runtime timed out.', False, request_id, trace_id)
                    # CPython's stdlib HTTPResponse exposes the underlying socket through its buffered reader.
                    reader = getattr(response_stream, 'fp', None)
                    transport = getattr(getattr(reader, 'raw', None), '_sock', None)
                    if transport is not None:
                        transport.settimeout(remaining)
                    elif reader is not None:
                        raise CoreviaError('REMOTE_UNAVAILABLE', 'Transport cannot enforce its read deadline.', False, request_id, trace_id)
                    chunk = response_stream.read1(min(16384, self.max_bytes + 1 - len(raw)))
                    if context is not None:
                        context.check()
                    if time.monotonic() >= end:
                        raise CoreviaError('REMOTE_TIMEOUT', 'Remote runtime timed out.', False, request_id, trace_id)
                    if not chunk:
                        break
                    raw.extend(chunk)
            if len(raw) > self.max_bytes:
                raise CoreviaError("PAYLOAD_TOO_LARGE", "Response too large.")
            value = validate_envelope(json.loads(raw))
            if value["id"] != request_id or value["cvp"] != self.version or "ok" not in value:
                raise CoreviaError("PROTOCOL_ERROR", "Mismatched response.")
            if not value["ok"]:
                raise CoreviaError(value["error"]["code"], value["error"]["message"], value["error"]["retryable"], request_id, trace_id)
            if status >= 400:
                raise CoreviaError("HTTP_ERROR", "HTTP error.", request_id=request_id, trace_id=trace_id)
            return value
        except CoreviaError:
            raise
        except (URLError, TimeoutError) as error:
            raise CoreviaError("REMOTE_UNAVAILABLE", "Remote runtime unavailable.", True, request_id, trace_id) from error
        except (ValueError, KeyError) as error:
            raise CoreviaError("PROTOCOL_ERROR", "Invalid remote response.", False, request_id, trace_id) from error
