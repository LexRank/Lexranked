/**
 * Minimal OpenAI Responses API client for structured (JSON Schema, strict)
 * output. Plain fetch instead of the SDK: one endpoint is used and the
 * worker has no runtime dependencies.
 *
 * - `store: false`: requests are not retained for later retrieval;
 * - retries on 429/5xx/network with backoff; refusals, incomplete output,
 *   invalid JSON and schema violations are errors, never "best effort";
 * - a per-job call budget caps cost;
 * - the API key is only ever placed in the Authorization header.
 */

import { assertStrictSchema, validateJson, type JsonSchema } from './jsonSchema.js';

export type AiErrorKind = 'refusal' | 'incomplete' | 'invalid_json' | 'schema' | 'http' | 'budget' | 'network';

export class AiError extends Error {
  constructor(
    message: string,
    readonly kind: AiErrorKind,
  ) {
    super(message);
  }
}

export interface StructuredRequest {
  /** Schema name (letters, digits, _ and -). */
  name: string;
  schema: JsonSchema;
  system: string;
  user: string;
  maxOutputTokens?: number;
}

export interface AiResult<T> {
  data: T;
  model: string;
  usage: { inputTokens: number; outputTokens: number };
}

export interface AiClient {
  readonly model: string;
  structured<T>(req: StructuredRequest): Promise<AiResult<T>>;
  /** Calls made since the last reset. */
  readonly calls: number;
  resetBudget(): void;
}

export interface OpenAIOptions {
  apiKey: string;
  model: string;
  baseUrl?: string;
  maxCalls?: number;
  timeoutMs?: number;
  retries?: number;
  fetchImpl?: typeof fetch;
  sleep?: (ms: number) => Promise<void>;
}

const RETRYABLE = new Set([408, 409, 429, 500, 502, 503, 504]);

export class OpenAIClient implements AiClient {
  readonly model: string;
  private used = 0;
  private readonly fetchImpl: typeof fetch;
  private readonly sleep: (ms: number) => Promise<void>;

  constructor(private readonly opts: OpenAIOptions) {
    this.model = opts.model;
    this.fetchImpl = opts.fetchImpl ?? fetch;
    this.sleep = opts.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
  }

  get calls(): number {
    return this.used;
  }

  resetBudget(): void {
    this.used = 0;
  }

  async structured<T>(req: StructuredRequest): Promise<AiResult<T>> {
    assertStrictSchema(req.schema);
    if (this.used >= (this.opts.maxCalls ?? 200)) {
      throw new AiError(`AI call budget of ${this.opts.maxCalls ?? 200} per job exhausted`, 'budget');
    }
    this.used++;

    const body = {
      model: this.model,
      store: false,
      input: [
        { role: 'system', content: req.system },
        { role: 'user', content: req.user },
      ],
      text: { format: { type: 'json_schema', name: req.name, schema: req.schema, strict: true } },
      ...(req.maxOutputTokens ? { max_output_tokens: req.maxOutputTokens } : {}),
    };

    const retries = this.opts.retries ?? 3;
    let last: AiError = new AiError('No response', 'network');
    for (let attempt = 0; attempt <= retries; attempt++) {
      if (attempt > 0) await this.sleep(1000 * 2 ** (attempt - 1) + Math.round(Math.random() * 500));
      let res: Response;
      try {
        res = await this.fetchImpl(`${(this.opts.baseUrl ?? 'https://api.openai.com/v1').replace(/\/+$/, '')}/responses`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${this.opts.apiKey}`, 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
          signal: AbortSignal.timeout(this.opts.timeoutMs ?? 60_000),
        });
      } catch (err) {
        last = new AiError(`OpenAI request failed: ${(err as Error).message}`, 'network');
        continue;
      }
      const payload = (await res.json().catch(() => null)) as Record<string, unknown> | null;
      if (!res.ok) {
        const message = ((payload?.error as { message?: string } | undefined)?.message ?? `HTTP ${res.status}`).slice(0, 300);
        last = new AiError(`OpenAI HTTP ${res.status}: ${message}`, 'http');
        if (RETRYABLE.has(res.status)) continue;
        throw last;
      }
      return this.parse<T>(payload ?? {}, req.schema);
    }
    throw last;
  }

  private parse<T>(payload: Record<string, unknown>, schema: JsonSchema): AiResult<T> {
    if (payload.status === 'incomplete') {
      const reason = (payload.incomplete_details as { reason?: string } | null)?.reason ?? 'unknown';
      throw new AiError(`Model output incomplete (${reason})`, 'incomplete');
    }
    let text: string | null = null;
    for (const item of (payload.output as Array<Record<string, unknown>> | undefined) ?? []) {
      if (item.type !== 'message') continue;
      for (const part of (item.content as Array<Record<string, unknown>> | undefined) ?? []) {
        if (part.type === 'refusal') throw new AiError(`Model refused: ${String(part.refusal ?? '').slice(0, 200)}`, 'refusal');
        if (part.type === 'output_text' && typeof part.text === 'string') text = (text ?? '') + part.text;
      }
    }
    if (text === null) throw new AiError('Model returned no text output', 'invalid_json');
    let data: unknown;
    try {
      data = JSON.parse(text);
    } catch {
      throw new AiError('Model output is not valid JSON', 'invalid_json');
    }
    const errors = validateJson(schema, data);
    if (errors.length > 0) {
      throw new AiError(`Model output violates the schema: ${errors.slice(0, 5).join('; ')}`, 'schema');
    }
    const usage = (payload.usage as { input_tokens?: number; output_tokens?: number } | undefined) ?? {};
    return {
      data: data as T,
      model: typeof payload.model === 'string' ? payload.model : this.model,
      usage: { inputTokens: usage.input_tokens ?? 0, outputTokens: usage.output_tokens ?? 0 },
    };
  }
}
