/**
 * Ranking page content drafted by a model from numbered facts only.
 * The schema forces every paragraph and FAQ answer to cite fact IDs from
 * the supplied list, so QA can check each statement against its sources.
 */

import type { AiClient } from '../ai/openai.js';
import { interpretationSystem } from '../ai/interpretation.js';
import type { JsonSchema } from '../ai/jsonSchema.js';
import type { Fact, RankingData } from './facts.js';
import { factForModel } from './facts.js';
import { place } from './facts.js';
import type { Unit } from './qa.js';

export const CONTENT_PROMPT_VERSION = 'ranking-content/2';

export interface Generated {
  summary: string;
  summaryFactRefs: string[];
  /** `bullets` (articles only): a short list shown after the section's first, answering paragraph. */
  sections: { heading: string; paragraphs: { text: string; factRefs: string[] }[]; bullets?: { text: string; factRefs: string[] }[] }[];
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
  const system = interpretationSystem('write', [
    'You write the editorial text for a LexRanked ranking page.',
    'Every paragraph, the summary and every FAQ answer must list the IDs of the facts it relies on in factRefs.',
    'When you mention a position, use the exact position from the facts. Describe positions as reflecting the LexRank score and methodology; to explain a position, use the "Why" facts.',
    'Write: a 1-3 sentence answer-first summary; up to 4 short sections (e.g. who leads the ranking, how positions are decided, what the market looks like, what to check when choosing); and 3-5 FAQs that a reader would actually ask.',
  ]);
  const user = JSON.stringify({
    page: { title: ranking.title, place: place(ranking), practiceArea: ranking.practiceArea?.name ?? null },
    facts: facts.map(factForModel),
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

export function unitsOf(c: Generated & { title?: string }): Unit[] {
  const units: Unit[] = [{ where: 'summary', text: c.summary, factRefs: c.summaryFactRefs }];
  if (c.title) units.unshift({ where: 'title', text: c.title, factRefs: [] });
  c.sections.forEach((s, i) => {
    units.push({ where: `sections[${i}].heading`, text: s.heading, factRefs: [] });
    s.paragraphs.forEach((p, j) => units.push({ where: `sections[${i}].paragraphs[${j}]`, text: p.text, factRefs: p.factRefs }));
    (s.bullets ?? []).forEach((b, j) => units.push({ where: `sections[${i}].bullets[${j}]`, text: b.text, factRefs: b.factRefs }));
  });
  c.faq.forEach((f, i) => {
    units.push({ where: `faq[${i}].question`, text: f.question, factRefs: f.factRefs });
    units.push({ where: `faq[${i}].answer`, text: f.answer, factRefs: f.factRefs });
  });
  return units;
}

// ─── Phase 7: hubs, profiles, articles ────────────────────────────────────

export const HUB_PROMPT_VERSION = 'hub-content/2';
export const PROFILE_PROMPT_VERSION = 'profile-summary/2';
export const ARTICLE_PROMPT_VERSION = 'article/4';

/** Hub pages (state, city, practice area): same shape as ranking content. */
export async function generateHubContent(ai: AiClient, page: { title: string; place: string }, facts: Fact[]): Promise<{ content: Generated; model: string }> {
  const system = interpretationSystem('write', [
    'You write the editorial text for a LexRanked location or practice-area page that lists lawyer profiles and rankings.',
    'Market figures (counts, average rating, median reviews) are computed by LexRanked; quote them exactly as given, with their sample size where it is stated.',
    'Write: a 1-3 sentence answer-first summary; up to 3 short sections (what the page covers, what the market looks like, how profiles are ordered, what to check when choosing); and 3-5 FAQs a reader would actually ask.',
  ]);
  const res = await ai.structured<Generated>({
    name: 'hub_content',
    schema: contentSchema(facts.map((f) => f.id)),
    system,
    user: JSON.stringify({ page, facts: facts.map(factForModel) }),
    maxOutputTokens: 3500,
  });
  return { content: res.data, model: res.model };
}

export interface ProfileSummary {
  summary: string;
  summaryFactRefs: string[];
}

export function profileSchema(factIds: string[]): JsonSchema {
  return {
    type: 'object',
    additionalProperties: false,
    required: ['summary', 'summaryFactRefs'],
    properties: {
      summary: { type: 'string', maxLength: 700 },
      summaryFactRefs: { type: 'array', minItems: 1, maxItems: 10, items: { type: 'string', enum: factIds } },
    },
  };
}

export async function generateProfileSummary(ai: AiClient, facts: Fact[]): Promise<{ content: ProfileSummary; model: string }> {
  const system = interpretationSystem('summarize', [
    'You write a 2-4 sentence, answer-first summary for a LexRanked lawyer or law firm profile: who they are, where they practise, what they focus on and how LexRanked scores and verifies them.',
  ]);
  const res = await ai.structured<ProfileSummary>({
    name: 'profile_summary',
    schema: profileSchema(facts.map((f) => f.id)),
    system,
    user: JSON.stringify({ facts: facts.map(factForModel) }),
    maxOutputTokens: 800,
  });
  return { content: res.data, model: res.model };
}

export interface GeneratedArticle extends Generated {
  title: string;
}

export function articleSchema(factIds: string[]): JsonSchema {
  // Guidance paragraphs may cite nothing (general advice); QA then forbids names and numbers in them.
  const refs: JsonSchema = { type: 'array', maxItems: 8, items: { type: 'string', enum: factIds } };
  return {
    type: 'object',
    additionalProperties: false,
    required: ['title', 'summary', 'summaryFactRefs', 'sections', 'faq'],
    properties: {
      title: { type: 'string', maxLength: 110 },
      summary: { type: 'string', maxLength: 400 },
      summaryFactRefs: refs,
      sections: {
        type: 'array',
        minItems: 3,
        maxItems: 8,
        items: {
          type: 'object',
          additionalProperties: false,
          required: ['heading', 'paragraphs', 'bullets'],
          properties: {
            heading: { type: 'string', maxLength: 100 },
            paragraphs: {
              type: 'array',
              minItems: 1,
              maxItems: 4,
              items: { type: 'object', additionalProperties: false, required: ['text', 'factRefs'], properties: { text: { type: 'string', maxLength: 900 }, factRefs: refs } },
            },
            bullets: {
              type: 'array',
              maxItems: 8,
              items: { type: 'object', additionalProperties: false, required: ['text', 'factRefs'], properties: { text: { type: 'string', maxLength: 300 }, factRefs: refs } },
            },
          },
        },
      },
      faq: {
        type: 'array',
        minItems: 6,
        maxItems: 10,
        items: { type: 'object', additionalProperties: false, required: ['question', 'answer', 'factRefs'], properties: { question: { type: 'string', maxLength: 200 }, answer: { type: 'string', maxLength: 600 }, factRefs: refs } },
      },
    },
  };
}

export async function generateArticle(ai: AiClient, topic: string, facts: Fact[]): Promise<{ content: GeneratedArticle; model: string }> {
  const system = interpretationSystem('write', [
    'You draft an editorial guide for LexRanked, a site that ranks US lawyers with a published, data-driven methodology.',
    'The topic is an editor\'s brief, not a fact. Practical, general guidance (what to check, what to ask) needs no citation, but must not contain names, numbers, statistics, laws or claims about specific people or firms.',
    'Anything about specific lawyers, firms, rankings, scores or the methodology must come from the numbered facts, cited in factRefs.',
    'Cover the topic completely: every question a reader searching for it would ask, each answered.',
    'Under every heading, the first paragraph answers that heading directly in one or two sentences; the detail follows in the next paragraphs.',
    'Use a short bullet list in a section when it helps (steps, checklists, what to bring, red flags); leave bullets empty otherwise.',
    'Make the first section "The short version": 4-6 bullets with the key answers.',
    'Be as short as possible while leaving no question unanswered: no filler, no repetition, no padding to reach a length.',
    'Write a clear title, a 1-2 sentence answer-first summary, 3-8 sections and 6-10 FAQs covering the follow-up questions a reader would still have; each answer starts with the direct answer.',
  ]);
  const res = await ai.structured<GeneratedArticle>({
    name: 'article_draft',
    schema: articleSchema(facts.map((f) => f.id)),
    system,
    user: JSON.stringify({ topic, facts: facts.map(factForModel) }),
    maxOutputTokens: 5000,
  });
  return { content: res.data, model: res.model };
}
