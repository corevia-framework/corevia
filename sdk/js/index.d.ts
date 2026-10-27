export interface Envelope { cvp: '1.0' | '2.0'; id: string; type?: string; target?: string; payload?: unknown; meta?: { trace_id?: string | null; parent_trace_id?: string; timeout_ms?: number; event_version?: number }; ok?: boolean; result?: unknown; error?: { code: string; message: string; retryable: boolean } | null }
export interface ClientOptions { baseUrl: string; realtimeUrl?: string; token?: string | null | (() => string | null | Promise<string | null>); timeout?: number; reconnectDelay?: number; maxReconnectDelay?: number; maxBytes?: number; version?: '1.0' | '2.0'; fetch?: typeof fetch; WebSocket?: typeof WebSocket }
export interface CallOptions { traceId?: string; requestId?: string; parentTraceId?: string; signal?: AbortSignal }
export class CoreviaError extends Error { code: string; retryable: boolean; requestId?: string; traceId?: string }
export function validateEnvelope(value: unknown): Envelope;
export class CoreviaClient {
  constructor(options: ClientOptions);
  call<T = unknown>(target: string, payload: unknown, options?: CallOptions): Promise<{ data: T; requestId: string; traceId: string }>;
  action<T = unknown>(target: string, payload: unknown, options?: CallOptions): Promise<{ data: T; requestId: string; traceId: string }>;
  connect(): void;
  disconnect(): void;
  onError(handler: (error: CoreviaError) => void): () => void;
  channel(name: string): { on(event: string, handler: (data: unknown, envelope: Envelope) => void): () => void; unsubscribe(): void };
}
export function createCorevia(options: Omit<ClientOptions, 'baseUrl'> & { baseURL: string }): CoreviaClient;
