export class SyncError extends Error { code: string; constructor(code: string); }
export interface SyncStateStore { read(): Promise<{version: number; payload: string | null}>; compareExchange(version: number, payload: string): Promise<boolean>; }
export class MemorySyncStore implements SyncStateStore { read(): Promise<{version: number; payload: string | null}>; compareExchange(version: number, payload: string): Promise<boolean>; }
export type SyncData = Record<string, unknown> | unknown[] | null;
export interface SyncMutation {operation_id: string; id: string; expected_revision: number; data: SyncData;}
export interface SyncAcknowledgment {operation_id: string; id: string; revision: number; sequence: number; deleted: boolean;}
export interface SyncChange {id: string; revision: number; sequence: number; deleted: boolean; data: SyncData;}
export interface SyncTransport {mutate(mutation: SyncMutation): Promise<SyncAcknowledgment>; changes(cursor: string | null): Promise<{changes: SyncChange[]; cursor: string; has_more: boolean}>;}
export interface SyncSnapshot {format: 1; binding: string; cursor: string | null; sequence: number; pending: (SyncMutation & {state: 'pending' | 'conflict'})[]; records: Record<string, {revision: number; deleted: boolean; data: SyncData}>;}
export class OfflineSync {
  constructor(options: {store: SyncStateStore; binding: string; newOperationId?: () => string});
  snapshot(): Promise<SyncSnapshot>;
  enqueue(id: string, expectedRevision: number, value: SyncData): Promise<string>;
  flush(transport: SyncTransport, options?: {maximum?: number}): Promise<{status: string; processed: number}>;
  pull(transport: SyncTransport, options?: {maximumPages?: number}): Promise<{status: string; pages: number}>;
  resolveConflict(operation: string, options: {action: 'drop'} | {action: 'rebase'; expectedRevision: number; value: SyncData}): Promise<string | null>;
  resetFeed(): Promise<void>;
  reset(): Promise<void>;
}
export function createSyncTransport(options: {baseUrl: string; getToken: () => string | null | Promise<string | null>; fetchImpl?: typeof fetch; timeoutMs?: number; developmentLoopback?: boolean}): SyncTransport;
