export class CoreviaError extends Error {
  constructor(code, message, { retryable = false, requestId, traceId } = {}) {
    super(message);
    this.name = 'CoreviaError';
    Object.assign(this, { code, retryable, requestId, traceId });
  }
}

const id = () => globalThis.crypto?.randomUUID?.() ?? `req_${Date.now()}_${Math.random().toString(36).slice(2)}`;
// Works in React Native without requiring TextEncoder or Node's Buffer.
const byteLength = text => {
  let length = 0;
  for (const character of text) {
    const point = character.codePointAt(0);
    length += point <= 0x7f ? 1 : point <= 0x7ff ? 2 : point <= 0xffff ? 3 : 4;
  }
  return length;
};
const types = ['action.call', 'action.result', 'event', 'subscribe', 'unsubscribe', 'ping', 'pong'];
export function validateEnvelope(value) {
  const visited = new Set();
  const safe = (item, depth = 0) => {
    if (depth > 60) throw new CoreviaError('NON_JSON_VALUE', 'Envelope nesting exceeds the limit.');
    if (item === null || typeof item === 'string' || typeof item === 'boolean') return;
    if (typeof item === 'number' && Number.isFinite(item)) return;
    if (typeof item !== 'object' || (!Array.isArray(item) && ![Object.prototype, null].includes(Object.getPrototypeOf(item))) || visited.has(item)) {
      throw new CoreviaError('NON_JSON_VALUE', 'Envelopes require finite JSON-compatible values.');
    }
    visited.add(item);
    for (const child of Object.values(item)) safe(child, depth + 1);
    visited.delete(item);
  };
  safe(value);
  if (!value || Array.isArray(value) || !['1.0', '2.0'].includes(value.cvp) || typeof value.id !== 'string' || !value.id || value.id.length > 128) {
    throw new CoreviaError('INVALID_ENVELOPE', 'Invalid CVP/1 envelope.');
  }
  if (value.meta != null && (typeof value.meta !== 'object' || Array.isArray(value.meta) || (value.meta.trace_id != null && (typeof value.meta.trace_id !== 'string' || value.meta.trace_id.length > 128)))) {
    throw new CoreviaError('INVALID_META', 'Invalid metadata.');
  }
  if ('ok' in value) {
    if (value.cvp === '2.0' && value.type !== 'action.result') throw new CoreviaError('INVALID_MESSAGE_TYPE', 'Invalid response type.');
    if (typeof value.ok !== 'boolean' || !('result' in value) || !('error' in value) || (value.ok && value.error !== null) || (!value.ok && (typeof value.error?.code !== 'string' || typeof value.error?.message !== 'string' || typeof value.error?.retryable !== 'boolean'))) {
      throw new CoreviaError('INVALID_RESPONSE', 'Invalid CVP response.');
    }
  } else if (!types.includes(value.type) || !value.payload || typeof value.payload !== 'object' || (['action.call', 'event', 'subscribe', 'unsubscribe'].includes(value.type) && (typeof value.target !== 'string' || !value.target || value.target.length > 128))) {
    throw new CoreviaError('INVALID_ENVELOPE', 'Invalid CVP message.');
  }
  if (value.cvp === '2.0') {
    for (const key of ['trace_id', 'parent_trace_id']) {
      const v = value.meta?.[key];
      if (v != null && (typeof v !== 'string' || !v || v.length > 128)) throw new CoreviaError('INVALID_META', 'Invalid trace metadata.');
    }
    const timeout = value.meta?.timeout_ms ?? 5000;
    if (!Number.isInteger(timeout) || timeout < 1 || timeout > 300000) throw new CoreviaError('INVALID_TIMEOUT', 'Invalid timeout.');
  }
  return value;
}

export class CoreviaClient {
  constructor({ baseUrl, realtimeUrl, token = () => null, timeout = 5000, reconnectDelay = 250, maxReconnectDelay = 10000, maxBytes = 65536, version = '1.0', fetch: fetchImpl = globalThis.fetch, WebSocket: Socket = globalThis.WebSocket }) {
    if (!baseUrl || !Number.isInteger(timeout) || timeout < 1 || timeout > 300000 || !Number.isInteger(maxBytes) || maxBytes < 1 || reconnectDelay < 1 || maxReconnectDelay < reconnectDelay) throw new TypeError('baseUrl, bounded timeout/size and valid reconnect delays are required.');
    Object.assign(this, { baseUrl: baseUrl.replace(/\/$/, ''), realtimeUrl, token, timeout, reconnectDelay, maxReconnectDelay, maxBytes, fetchImpl, Socket });
    if (!['1.0', '2.0'].includes(version)) throw new TypeError('Unsupported protocol version.');
    this.version = version;
    if (typeof this.token !== 'function') { const fixed = this.token; this.token = () => fixed; }
    this.channels = new Map();
    this.errorHandlers = new Set();
    this.socket = null;
    this.stopped = true;
    this.attempt = 0;
    this.timer = null;
    this.generation = 0;
  }

  envelope(type, target, payload = {}) {
    return { cvp: this.version, id: id(), type, target, payload, meta: { trace_id: id(), ...(this.version === '2.0' ? { timeout_ms: this.timeout } : {}) } };
  }

  async call(target, payload, { traceId = id(), requestId = id(), parentTraceId, signal } = {}) {
    const envelope = { ...this.envelope('action.call', target, payload), id: requestId, meta: { trace_id: traceId, ...(this.version === '2.0' ? { timeout_ms: this.timeout, ...(parentTraceId ? { parent_trace_id: parentTraceId } : {}) } : {}) } };
    validateEnvelope(envelope);
    const controller = new AbortController();
    const relay = () => controller.abort();
    if (signal?.aborted) relay();
    signal?.addEventListener('abort', relay, { once: true });
    let timer;
    const deadline = new Promise((_, reject) => {
      timer = setTimeout(() => { controller.abort(); reject(new CoreviaError('TIMEOUT', 'Action call timed out.', { retryable: true, requestId, traceId })); }, this.timeout);
    });
    try {
      return await Promise.race([deadline, (async () => {
        const token = await this.token();
        const headers = { 'Content-Type': 'application/json', 'X-Request-Id': requestId, 'X-Trace-Id': traceId };
        if (token) headers.Authorization = `Bearer ${token}`;
        const body = JSON.stringify(envelope);
        if (byteLength(body) > this.maxBytes) throw new CoreviaError('PAYLOAD_TOO_LARGE', 'Request exceeds maximum size.');
        const response = await this.fetchImpl(`${this.baseUrl}/cvp`, { method: 'POST', headers, body, signal: controller.signal });
        const text = await response.text();
        if (byteLength(text) > this.maxBytes) throw new CoreviaError('PAYLOAD_TOO_LARGE', 'Response exceeds maximum size.');
        let value;
        try { value = validateEnvelope(JSON.parse(text)); } catch { throw new CoreviaError('PROTOCOL_ERROR', 'Invalid server response.', { requestId, traceId }); }
        if (value.id !== requestId || value.cvp !== this.version || typeof value.ok !== 'boolean') throw new CoreviaError('PROTOCOL_ERROR', 'Response request id or version does not match.', { requestId, traceId });
        if (!value.ok) throw new CoreviaError(value.error.code, value.error.message, { retryable: value.error.retryable, requestId, traceId: value.meta?.trace_id ?? traceId });
        if (!response.ok) throw new CoreviaError('HTTP_ERROR', `HTTP ${response.status}`, { requestId, traceId });
        return { data: value.result, requestId: value.id, traceId: value.meta?.trace_id ?? traceId };
      })()]);
    } catch (error) {
      if (error instanceof CoreviaError) throw error;
      throw new CoreviaError(controller.signal.aborted ? (signal?.aborted ? 'ABORTED' : 'TIMEOUT') : 'NETWORK_ERROR', 'Action request failed.', { retryable: !signal?.aborted, requestId, traceId });
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort', relay);
    }
  }

  action(target, payload, options) { return this.call(target, payload, options); }

  onError(handler) { this.errorHandlers.add(handler); return () => this.errorHandlers.delete(handler); }
  report(error) { for (const handler of this.errorHandlers) handler(error); }

  connect() {
    if (!this.realtimeUrl || !this.Socket) throw new CoreviaError('REALTIME_UNAVAILABLE', 'Provide realtimeUrl and a WebSocket implementation.');
    this.stopped = false;
    if (this.socket && this.socket.readyState < 2) return;
    clearTimeout(this.timer);
    const socket = new this.Socket(this.realtimeUrl);
    this.socket = socket;
    const generation = ++this.generation;
    socket.onopen = () => {
      if (this.stopped || generation !== this.generation) return;
      this.attempt = 0;
      for (const name of this.channels.keys()) this.subscribe(name);
    };
    socket.onmessage = ({ data }) => {
      if (generation !== this.generation) return;
      try {
        if (typeof data !== 'string' || byteLength(data) > this.maxBytes) throw new CoreviaError('PAYLOAD_TOO_LARGE', 'Realtime message rejected.');
        const message = validateEnvelope(JSON.parse(data));
        if ('ok' in message && !message.ok) this.report(new CoreviaError(message.error.code, message.error.message, { requestId: message.id, traceId: message.meta?.trace_id, retryable: message.error.retryable }));
        if (message.type === 'event') {
          for (const [name, events] of this.channels) {
            const matches = name === message.target || (name.endsWith('.*') && !/^(private-|presence-)/.test(message.target) && message.target.startsWith(name.slice(0, -1)));
            if (matches) for (const handler of events.get(message.payload.event) ?? []) handler(message.payload.data, message);
          }
        }
        if (message.type === 'ping') this.send({ ...this.envelope('pong', '', {}), id: message.id });
      } catch (error) { this.report(error instanceof CoreviaError ? error : new CoreviaError('PROTOCOL_ERROR', 'Invalid realtime message.')); }
    };
    socket.onerror = () => this.report(new CoreviaError('REALTIME_ERROR', 'Realtime connection failed.', { retryable: true }));
    socket.onclose = () => {
      if (generation !== this.generation) return;
      this.socket = null;
      if (!this.stopped) {
        const delay = Math.min(this.maxReconnectDelay, this.reconnectDelay * 2 ** Math.min(this.attempt++, 16));
        this.timer = setTimeout(() => this.connect(), delay);
      }
    };
  }

  send(message) {
    if (this.socket?.readyState === 1) {
      validateEnvelope(message);
      const wire = JSON.stringify(message);
      if (byteLength(wire) > this.maxBytes) throw new CoreviaError('PAYLOAD_TOO_LARGE', 'Realtime request exceeds maximum size.');
      this.socket.send(wire);
    }
  }

  async subscribe(name) {
    const generation = this.generation;
    try {
      const token = await this.token();
      if (generation === this.generation && !this.stopped && this.channels.has(name)) this.send(this.envelope('subscribe', name, token ? { token } : {}));
    } catch { this.report(new CoreviaError('AUTH_ERROR', 'Unable to obtain subscription token.')); }
  }

  channel(name) {
    if (!/^[a-zA-Z0-9_.:*-]{1,128}$/.test(name) || (name.includes('*') && (!name.endsWith('.*') || name.slice(0, -1).includes('*') || /^(private-|presence-)/.test(name)))) throw new TypeError('Invalid channel name.');
    if (!this.channels.has(name)) {
      this.channels.set(name, new Map());
      if (this.socket?.readyState === 1) this.subscribe(name);
    }
    const client = this;
    return {
      on(event, handler) {
        if (!client.channels.has(name)) {
          client.channels.set(name, new Map());
          if (client.socket?.readyState === 1) client.subscribe(name);
        }
        const events = client.channels.get(name);
        if (!events.has(event)) events.set(event, new Set());
        events.get(event).add(handler);
        client.connect();
        return () => events.get(event)?.delete(handler);
      },
      unsubscribe() {
        client.channels.delete(name);
        client.send(client.envelope('unsubscribe', name));
      },
    };
  }

  disconnect() {
    this.stopped = true;
    ++this.generation;
    clearTimeout(this.timer);
    this.socket?.close();
    this.socket = null;
  }
}

/** V2 entry point; the CoreviaClient constructor retains the V1 default. */
export function createCorevia({ baseURL, ...options }) {
  return new CoreviaClient({ ...options, baseUrl: baseURL ?? options.baseUrl, version: options.version ?? '2.0' });
}
