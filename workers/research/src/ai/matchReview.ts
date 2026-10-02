/**
 * AI second opinion on candidates the deterministic matcher could not
 * decide. Advisory only: the verdict is stored as a note an editor sees next
 * to the candidate; it never merges, creates or rejects anything.
 */

import type { AiClient } from './openai.js';
import type { JsonSchema } from './jsonSchema.js';

export const MATCH_PROMPT_VERSION = 'match-review/1';

export interface ReviewCandidate {
  id: number;
  entityType: 'lawyer' | 'law_firm';
  name: string;
  city: string | null;
  state: string | null;
  practiceArea: string | null;
  website: string | null;
  sourceUrl: string;
  reason: string | null;
  suggested: null | {
    id: number;
    name: string;
    status: string;
    city: string | null;
    state: string | null;
    website: string | null;
    practiceAreas: string[];
  };
}

export interface MatchVerdict {
  verdict: 'same' | 'different' | 'unsure';
  confidence: number;
  reason: string;
}

export const MATCH_SCHEMA: JsonSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['verdict', 'confidence', 'reason'],
  properties: {
    verdict: { type: 'string', enum: ['same', 'different', 'unsure'] },
    confidence: { type: 'number', minimum: 0, maximum: 1 },
    reason: { type: 'string', maxLength: 300 },
  },
};

export async function reviewMatch(ai: AiClient, c: ReviewCandidate): Promise<MatchVerdict & { model: string }> {
  if (!c.suggested) {
    throw new Error('No suggested profile to compare');
  }
  const system = [
    'You compare two records and say whether they describe the same US lawyer or law firm.',
    'Use only the fields given. Do not assume facts that are not present.',
    'Answer "same" only when the records agree on name and location or website; "different" when they clearly conflict; otherwise "unsure".',
    'Give a short, factual reason that cites the fields you compared. Your answer is advisory; a human decides.',
  ].join('\n');
  const user = JSON.stringify({
    candidate: { type: c.entityType, name: c.name, city: c.city, state: c.state, practiceArea: c.practiceArea, website: c.website },
    existingProfile: { name: c.suggested.name, city: c.suggested.city, state: c.suggested.state, website: c.suggested.website, practiceAreas: c.suggested.practiceAreas },
    matcherReason: c.reason,
  });
  const res = await ai.structured<MatchVerdict>({ name: 'match_review', schema: MATCH_SCHEMA, system, user, maxOutputTokens: 400 });
  return { ...res.data, confidence: Math.round(res.data.confidence * 100) / 100, model: res.model };
}
