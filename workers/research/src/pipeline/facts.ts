/**
 * Turning fetched pages into claims (shared by discovery and refresh).
 */

import { extractWithAi } from '../ai/extract.js';
import { AiError } from '../ai/openai.js';
import { htmlToText } from '../ai/text.js';
import type { ClaimInput } from '../api.js';
import { extractFacts } from '../extract.js';
import { FetchRefused } from '../fetcher.js';
import type { EntityType } from '../normalize.js';
import { bump, type JobContext, type Stats } from './context.js';

/** Confidence for facts a site states about itself in structured data. */
export const WEBSITE_CONFIDENCE = 0.8;

/** Confidence for quote-checked AI extraction (WordPress caps it at 0.6 too). */
export const AI_CONFIDENCE = 0.5;

const practiceAreaCache = new WeakMap<JobContext, Promise<{ slug: string; name: string }[]>>();

function practiceAreas(ctx: JobContext): Promise<{ slug: string; name: string }[]> {
  let cached = practiceAreaCache.get(ctx);
  if (!cached) {
    cached = ctx.api.getPublic<{ slug: string; name: string }[]>('/practice-areas').then((list) => list.map(({ slug, name }) => ({ slug, name })));
    practiceAreaCache.set(ctx, cached);
  }
  return cached;
}

/**
 * Optional AI extraction (job param ai_extraction: true) for pages without
 * matching structured data. Only quote-verified facts survive.
 */
async function aiClaims(ctx: JobContext, stats: Stats, entity: { id: number; type: EntityType; name: string }, page: { url: string; html: string; retrievedAt: string }): Promise<ClaimInput[]> {
  if (!ctx.ai || ctx.job.params.ai_extraction !== true) return [];
  const text = htmlToText(page.html);
  if (text.length < 40) return [];
  try {
    const res = await extractWithAi(ctx.ai, { entity: { type: entity.type, name: entity.name }, url: page.url, text, practiceAreas: await practiceAreas(ctx) });
    bump(stats, 'ai_facts_accepted', res.facts.length + (res.practiceAreas.length > 0 ? 1 : 0));
    bump(stats, 'ai_facts_rejected', res.rejected.length);
    if (res.rejected.length > 0) {
      ctx.log('info', 'ai', `Discarded ${res.rejected.length} unverifiable AI value(s) for #${entity.id}.`, { rejected: res.rejected.slice(0, 10) });
    }
    const base = { entity_id: entity.id, source_url: page.url, source_type: 'official_website', retrieved_at: page.retrievedAt, confidence: AI_CONFIDENCE, method: 'ai' as const };
    const claims: ClaimInput[] = res.facts.map((f) => ({ ...base, field_name: f.field_name, value: f.value }));
    if (res.practiceAreas.length > 0) claims.push({ ...base, field_name: 'practice_areas', value: res.practiceAreas });
    return claims;
  } catch (err) {
    if (err instanceof AiError && err.kind !== 'network' && err.kind !== 'http') {
      bump(stats, 'ai_rejected_outputs');
      ctx.log('warning', 'ai', `AI extraction for #${entity.id} unusable: ${err.message}`);
      return [];
    }
    throw err;
  }
}

export async function websiteClaims(
  ctx: JobContext,
  stats: Stats,
  entity: { id: number; type: EntityType; name: string; website: string },
): Promise<ClaimInput[]> {
  let page;
  try {
    page = await ctx.fetcher.fetchHtml(entity.website);
  } catch (err) {
    if (err instanceof FetchRefused) {
      bump(stats, 'fetch_skipped');
      ctx.log('info', 'fetch', `Skipped ${entity.website}: ${err.message}`, { entity_id: entity.id, reason: err.reason });
    } else {
      bump(stats, 'fetch_failed');
      ctx.log('warning', 'fetch', `Could not fetch ${entity.website}: ${(err as Error).message}`, { entity_id: entity.id });
    }
    return [];
  }
  bump(stats, 'pages_fetched');
  const { matched, facts } = extractFacts(page.html, page.url, entity);
  if (!matched) {
    bump(stats, 'pages_without_data');
    ctx.log('info', 'extract', `No structured data about "${entity.name}" on ${page.url}`, { entity_id: entity.id });
    return aiClaims(ctx, stats, entity, page);
  }
  return facts.map((f) => ({
    entity_id: entity.id,
    field_name: f.field_name,
    value: f.value,
    source_url: page.url,
    source_type: 'official_website',
    retrieved_at: page.retrievedAt,
    confidence: WEBSITE_CONFIDENCE,
    method: 'structured_data' as const,
  }));
}
