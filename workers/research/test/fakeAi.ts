/** Scripted AI client for tests: answers by schema name, validates like the real one. */

import { assertStrictSchema, validateJson } from '../src/ai/jsonSchema.js';
import { AiError, type AiClient, type AiResult, type StructuredRequest } from '../src/ai/openai.js';

export type Responder = (req: StructuredRequest) => unknown;

export class FakeAi implements AiClient {
  readonly model = 'fake-model';
  calls = 0;
  requests: StructuredRequest[] = [];
  constructor(private readonly responders: Record<string, Responder>) {}

  resetBudget(): void {
    this.calls = 0;
  }

  async structured<T>(req: StructuredRequest): Promise<AiResult<T>> {
    assertStrictSchema(req.schema);
    this.calls++;
    this.requests.push(req);
    const responder = this.responders[req.name];
    if (!responder) throw new AiError(`no responder for ${req.name}`, 'refusal');
    const data = responder(req);
    const errors = validateJson(req.schema, data);
    if (errors.length > 0) throw new AiError(`schema: ${errors.join('; ')}`, 'schema');
    return { data: data as T, model: this.model, usage: { inputTokens: 0, outputTokens: 0 } };
  }
}
