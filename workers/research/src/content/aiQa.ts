/**
 * Optional AI review of a draft against its facts. It can only add issues
 * (never clear deterministic ones), and every issue must quote an excerpt
 * that really is in the draft.
 */

import type { AiClient } from '../ai/openai.js';
import type { JsonSchema } from '../ai/jsonSchema.js';
import { canonical } from '../ai/text.js';
import type { Fact } from './facts.js';
import { factForModel } from './facts.js';
import type { Issue, Unit } from './qa.js';

export const QA_SCHEMA: JsonSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['issues'],
  properties: {
    issues: {
      type: 'array',
      maxItems: 20,
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['code', 'severity', 'excerpt', 'message'],
        properties: {
          code: { type: 'string', enum: ['unsupported_claim', 'factual_inconsistency', 'unnatural_language', 'missing_context', 'misleading'] },
          severity: { type: 'string', enum: ['error', 'warning'] },
          excerpt: { type: 'string', maxLength: 200 },
          message: { type: 'string', maxLength: 300 },
        },
      },
    },
  },
};

export async function aiReviewContent(ai: AiClient, units: Unit[], facts: Fact[]): Promise<Issue[]> {
  const system = [
    'You are a strict fact-checker for a lawyer ranking site. Compare each text unit with the numbered facts it cites.',
    'Report: statements not supported by the facts (unsupported_claim), contradictions (factual_inconsistency), misleading framing (misleading), clumsy or robotic phrasing (unnatural_language, warning), and missing context a reader needs (missing_context, warning).',
    'Quote the problematic words exactly in "excerpt". Report nothing if the text is accurate. Do not rewrite the text.',
  ].join('\n');
  const user = JSON.stringify({ facts: facts.map(factForModel), units });
  const res = await ai.structured<{ issues: Issue[] }>({ name: 'content_qa', schema: QA_SCHEMA, system, user, maxOutputTokens: 1500 });
  const draft = canonical(units.map((u) => u.text).join(' '));
  return res.data.issues
    .filter((i) => i.excerpt.trim() !== '' && draft.includes(canonical(i.excerpt)))
    .map((i) => ({ ...i, code: `ai_${i.code}`, message: `AI review: ${i.message}` }));
}
