export interface AiStreamOptions { message: string; csrf: string; signal?: AbortSignal; fetchImpl?: typeof fetch; }
export function streamAi(url: string | URL, options: AiStreamOptions): AsyncGenerator<string, void, unknown>;
