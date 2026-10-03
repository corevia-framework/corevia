export interface Envelope { cvp: '1.0'; id: string; type?: string; target?: string; payload?: unknown; meta?: { trace_id?: string }; ok?: boolean; result?: unknown; error?: { code: string; message: string; retryable: boolean } | null }
export class CoreviaError extends Error { code: string; retryable: boolean; requestId?: string; traceId?: string }
export function validateEnvelope(value: unknown): Envelope;
export class CoreviaClient {
  constructor(options: { baseUrl: string; realtimeUrl?: string; token?: () => string | null | Promise<string | null>; timeout?: number; reconnectDelay?: number; maxReconnectDelay?: number; maxBytes?: number; fetch?: typeof fetch; WebSocket?: typeof WebSocket });
  call<T = unknown>(target: string, payload: unknown, options?: { traceId?: string; requestId?: string; signal?: AbortSignal }): Promise<{ data: T; requestId: string; traceId: string }>;
  connect(): void;
  disconnect(): void;
  onError(handler: (error: CoreviaError) => void): () => void;
  channel(name: string): { on(event: string, handler: (data: unknown, envelope: Envelope) => void): () => void; unsubscribe(): void };
}
