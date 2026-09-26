/**
 * candidate_discovery: seed dataset → candidates (matched / drafted / review)
 * → source-backed claims and verification requests for decided entities.
 *
 * Cursor: "row:<n>" = number of dataset rows fully processed.
 */

import { isItemError, type ClaimInput, type VerificationInput } from '../api.js';
import { loadDataset, type SeedRow } from '../providers/csvSeed.js';
import { assertNotAborted, bump, type JobContext, type PipelineResult, type Stats } from './context.js';
import { websiteClaims } from './facts.js';

const AUTHORITATIVE = new Set(['official_registry', 'government', 'bar_association']);

export function parseRowCursor(cursor: string | null): number {
  const m = /^row:(\d+)$/.exec(cursor ?? '');
  return m ? Number(m[1]) : 0;
}

/** Claims that restate what the curated source says (never more). */
export function rowClaims(row: SeedRow, entityId: number, sourceId: number | undefined): ClaimInput[] {
  const base = {
    entity_id: entityId,
    source_url: row.source_url,
    source_type: row.source_type,
    retrieved_at: row.retrieved_at,
    confidence: row.confidence,
    method: 'seed' as const,
    ...(sourceId !== undefined ? { source_id: sourceId } : {}),
  };
  const fields: [string, unknown][] = [
    ['name', row.name],
    ['city', row.city],
    ['state', row.state],
    ['practice_areas', row.practice_area ? [row.practice_area] : undefined],
    ['website', row.website],
    ['phone', row.phone],
  ];
  if (row.entity_type === 'lawyer') {
    fields.push(['bar_state', row.bar_state], ['bar_number', row.bar_number], ['bar_status', row.bar_status]);
  }
  return fields.filter(([, v]) => v !== undefined && v !== '').map(([field_name, value]) => ({ ...base, field_name, value }));
}

/** Verification requests; the server decides the final status by source tier. */
export function rowVerifications(row: SeedRow, entityId: number, sourceId: number | undefined): VerificationInput[] {
  if (row.entity_type !== 'lawyer' || !row.bar_status || !AUTHORITATIVE.has(row.source_type)) return [];
  const base = {
    entity_id: entityId,
    source_url: row.source_url,
    source_type: row.source_type,
    notes: `Seed dataset row ${row.line}; source read ${row.retrieved_at.slice(0, 10)}.`,
    ...(sourceId !== undefined ? { source_id: sourceId } : {}),
  };
  return [
    { ...base, verification_type: 'license', status: row.bar_status.toLowerCase() === 'active' ? 'verified' : 'failed' },
    { ...base, verification_type: 'bar_status', status: 'verified' },
  ];
}

export async function runDiscovery(ctx: JobContext): Promise<PipelineResult> {
  const params = ctx.job.params;
  const fetchWebsites = params.fetch_websites !== false;
  const parsed = await loadDataset(ctx.config.dataDir, params.dataset);
  const stats: Stats = { ...(ctx.job.stats ?? {}) };
  let processed = ctx.job.processedCount;
  let position = Math.min(parseRowCursor(ctx.job.cursor), parsed.length);
  let rowsThisRun = 0;

  if (position > 0) {
    ctx.log('info', 'resume', `Resuming at row ${position + 1} of ${parsed.length}.`);
  }

  while (position < parsed.length) {
    assertNotAborted(ctx.signal);
    const batch = parsed.slice(position, position + ctx.config.batchSize);
    const rows: SeedRow[] = [];
    for (const item of batch) {
      if (item.ok) {
        rows.push(item.row);
      } else {
        bump(stats, 'rows_invalid');
        ctx.log('warning', 'parse', `Row ${item.line} skipped: ${item.error}.`);
      }
    }

    // 1. Sources (find-or-create by URL).
    const urls = [...new Map(rows.map((r) => [r.source_url, r])).values()];
    const sourceIds = new Map<string, number>();
    if (urls.length > 0) {
      const res = await ctx.api.sources(ctx.job, urls.map((r) => ({ url: r.source_url, source_type: r.source_type })));
      res.forEach((r, i) => {
        const url = urls[i]?.source_url;
        if (url === undefined) return;
        if (isItemError(r)) {
          ctx.log('warning', 'source', `Source ${url} rejected: ${r.error.message}.`);
        } else {
          sourceIds.set(url, r.sourceId);
        }
      });
    }

    // 2. Candidates (matched / drafted / needs review — decided server-side).
    const claims: ClaimInput[] = [];
    const verifications: VerificationInput[] = [];
    if (rows.length > 0) {
      const results = await ctx.api.candidates(
        ctx.job,
        rows.map((r) => ({
          entity_type: r.entity_type,
          name: r.name,
          source_url: r.source_url,
          source_type: r.source_type,
          ...(r.city ? { city: r.city } : {}),
          ...(r.state ? { state: r.state } : {}),
          ...(r.practice_area ? { practice_area: r.practice_area } : {}),
          ...(r.website ? { website: r.website } : {}),
          payload: { dataset: String(params.dataset), line: r.line },
        })),
      );
      for (const [i, result] of results.entries()) {
        const row = rows[i];
        if (!row) continue;
        if (isItemError(result)) {
          bump(stats, 'rows_invalid');
          ctx.log('warning', 'candidate', `Row ${row.line} rejected: ${result.error.message}.`);
          continue;
        }
        bump(stats, `candidates_${result.status}`);
        if (result.entityId === null) continue;
        const sourceId = sourceIds.get(row.source_url);
        claims.push(...rowClaims(row, result.entityId, sourceId));
        verifications.push(...rowVerifications(row, result.entityId, sourceId));
        if (fetchWebsites && row.website) {
          assertNotAborted(ctx.signal);
          claims.push(...(await websiteClaims(ctx, stats, { id: result.entityId, type: row.entity_type, name: row.name, website: row.website })));
        }
      }
    }

    // 3. Evidence and verification requests.
    if (claims.length > 0) {
      for (const r of await ctx.api.claims(ctx.job, claims)) {
        if (isItemError(r)) {
          bump(stats, 'claims_rejected');
          ctx.log('warning', 'claim', `Claim rejected: ${r.error.message}.`, { field: claims[r.index]?.field_name, entity_id: claims[r.index]?.entity_id });
        } else {
          bump(stats, r.duplicate ? 'claims_duplicate' : 'claims_stored');
        }
      }
    }
    if (verifications.length > 0) {
      for (const r of await ctx.api.verifications(ctx.job, verifications)) {
        if (isItemError(r)) {
          bump(stats, 'verifications_rejected');
          ctx.log('warning', 'verify', `Verification rejected: ${r.error.message}.`);
        } else if (!r.duplicate) {
          bump(stats, r.downgraded ? 'verifications_downgraded' : 'verifications_recorded');
        }
      }
    }

    position += batch.length;
    processed += batch.length;
    rowsThisRun += batch.length;
    await ctx.checkpoint(`row:${position}`, processed, stats);
    ctx.afterCheckpoint(rowsThisRun);
  }

  ctx.log('info', 'summary', `Processed ${parsed.length} dataset rows.`, stats);
  return { cursor: `row:${position}`, processed, stats };
}
