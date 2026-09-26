/**
 * Ranking page content drafted by a model from numbered facts only.
 * The schema forces every paragraph and FAQ answer to cite fact IDs from
 * the supplied list, so QA can check each statement against its sources.
 */

import type { AiClient } from '../ai/openai.js';
import type { JsonSchema } from '../ai/jsonSchema.js';
import type { Fact, RankingData } from './facts.js';
import { place } from './facts.js';
import type { Unit } from './qa.js';

export const CONTENT_PROMPT_VERSION = 'ranking-content/1';

export interface Generated {
  summary: string;
  summaryFactRefs: string[];
  sections: { heading: string; paragraphs: { text: string; factRefs: string[] }[] }[];
  faq: { question: string; answer: string; factRefs: string[] }[];
}

export function contentSchema(factIds: string[]): JsonSchema {
  const refs: JsonSchema = { type: 'array', minItems: 1, maxItems: 8, items: { type: 'string', enum: factIds } };
  return {
    type: 'object',
    additionalProperties: false,
    required: ['summary', 'summaryFactRefs', 'sections', 'faq'],
    properties: {
      summary: { type: 'string', maxLength: 600 },
      summaryFactRefs: refs,
      sections: {
        type: 'array',
        maxItems: 5,
        items: {
          type: 'object',
          additionalProperties: false,
          required: ['heading', 'paragraphs'],
          properties: {
            heading: { type: 'string', maxLength: 100 },
            paragraphs: {
              type: 'array',
              minItems: 1,
              maxItems: 4,
              items: {
                type: 'object',
                additionalProperties: false,
                required: ['text', 'factRefs'],
                properties: { text: { type: 'string', maxLength: 900 }, factRefs: refs },
              },
            },
          },
        },
      },
      faq: {
        type: 'array',
        maxItems: 6,
        items: {
          type: 'object',
          additionalProperties: false,
          required: ['question', 'answer', 'factRefs'],
          properties: { question: { type: 'string', maxLength: 200 }, answer: { type: 'string', maxLength: 600 }, factRefs: refs },
        },
      },
    },
  };
}

export async function generateRankingContent(ai: AiClient, ranking: RankingData, facts: Fact[]): Promise<{ content: Generated; model: string }> {
  const system = [
    'You write the editorial text for a LexRanked ranking page (US English, neutral, factual, helpful to someone choosing a lawyer).',
    'Use ONLY the numbered facts provided. Every paragraph, the summary and every FAQ answer must list the IDs of the facts it relies on in factRefs.',
    'Never add names, numbers, dates, awards, reviews, fees, outcomes or credentials that are not in the facts. Do not give legal advice.',
    'Do not call anyone "the best", do not promise results, do not include links, phone numbers or calls to action.',
    'When you mention a position, use the exact position from the facts. Describe positions as reflecting the LexRank score and methodology.',
    'Write: a 1–3 sentence answer-first summary; up to 4 short sections (e.g. who leads the ranking, how positions are decided, what to check when choosing); and 3–5 FAQs that a reader would actually ask.',
  ].join('\n');
  const user = JSON.stringify({
    page: { title: ranking.title, place: place(ranking), practiceArea: ranking.practiceArea?.name ?? null },
    facts: facts.map(({ id, label, value }) => ({ id, label, value })),
  });
  const res = await ai.structured<Generated>({
    name: 'ranking_content',
    schema: contentSchema(facts.map((f) => f.id)),
    system,
    user,
    maxOutputTokens: 4000,
  });
  return { content: res.data, model: res.model };
}

export function unitsOf(c: Generated): Unit[] {
  const units: Unit[] = [{ where: 'summary', text: c.summary, factRefs: c.summaryFactRefs }];
  c.sections.forEach((s, i) => {
    units.push({ where: `sections[${i}].heading`, text: s.heading, factRefs: [] });
    s.paragraphs.forEach((p, j) => units.push({ where: `sections[${i}].paragraphs[${j}]`, text: p.text, factRefs: p.factRefs }));
  });
  c.faq.forEach((f, i) => {
    units.push({ where: `faq[${i}].question`, text: f.question, factRefs: f.factRefs });
    units.push({ where: `faq[${i}].answer`, text: f.answer, factRefs: f.factRefs });
  });
  return units;
}
