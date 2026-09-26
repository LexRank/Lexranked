/**
 * Turning fetched pages into claims (shared by discovery and refresh).
 */

import type { ClaimInput } from '../api.js';
import { extractFacts } from '../extract.js';
import { FetchRefused } from '../fetcher.js';
import type { EntityType } from '../normalize.js';
import { bump, type JobContext, type Stats } from './context.js';

/** Confidence for facts a site states about itself in structured data. */
export const WEBSITE_CONFIDENCE = 0.8;

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
    return [];
  }
  return facts.map((f) => ({
    entity_id: entity.id,
    field_name: f.field_name,
    value: f.value,
    source_url: page.url,
    source_type: 'official_website',
    retrieved_at: page.retrievedAt,
    confidence: WEBSITE_CONFIDENCE,
  }));
}
