/**
 * source_refresh: re-read the websites of lawyers/firms in the job's scope
 * and submit what their structured data states now. Facts land in drafts
 * directly and in the editorial review queue for published profiles.
 *
 * Cursor: "id:<n>" = last entity ID processed (targets are served by ID).
 */

import { isItemError, type ClaimInput } from '../api.js';
import { assertNotAborted, bump, type JobContext, type PipelineResult, type Stats } from './context.js';
import { websiteClaims } from './facts.js';

export function parseIdCursor(cursor: string | null): number {
  const m = /^id:(\d+)$/.exec(cursor ?? '');
  return m ? Number(m[1]) : 0;
}

export async function runRefresh(ctx: JobContext): Promise<PipelineResult> {
  const stats: Stats = { ...(ctx.job.stats ?? {}) };
  let processed = ctx.job.processedCount;
  let after = parseIdCursor(ctx.job.cursor);
  let rowsThisRun = 0;

  for (;;) {
    assertNotAborted(ctx.signal);
    const targets = await ctx.api.targets(ctx.job, after, ctx.config.batchSize);
    if (targets.length === 0) break;

    const claims: ClaimInput[] = [];
    for (const t of targets) {
      assertNotAborted(ctx.signal);
      if (!t.website) {
        bump(stats, 'targets_without_website');
        continue;
      }
      claims.push(...(await websiteClaims(ctx, stats, { id: t.id, type: t.entityType, name: t.name, website: t.website })));
    }
    if (claims.length > 0) {
      for (const r of await ctx.api.claims(ctx.job, claims)) {
        if (isItemError(r)) {
          bump(stats, 'claims_rejected');
          ctx.log('warning', 'claim', `Claim rejected: ${r.error.message}.`);
        } else {
          bump(stats, r.duplicate ? 'claims_duplicate' : 'claims_stored');
        }
      }
    }

    after = (targets[targets.length - 1] as { id: number }).id;
    processed += targets.length;
    rowsThisRun += targets.length;
    await ctx.checkpoint(`id:${after}`, processed, stats);
    ctx.afterCheckpoint(rowsThisRun);
  }

  ctx.log('info', 'summary', `Refreshed ${processed} entities.`, stats);
  return { cursor: `id:${after}`, processed, stats };
}
