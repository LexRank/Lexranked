/**
 * ai_candidate_review: a second opinion from the model on candidates the
 * deterministic matcher sent to review. Stored as advisory notes only.
 *
 * Cursor: "cand:<n>" = last candidate ID reviewed.
 */

import { isItemError, type CandidateNoteInput } from '../api.js';
import { AiError } from '../ai/openai.js';
import { MATCH_PROMPT_VERSION, reviewMatch } from '../ai/matchReview.js';
import { ProviderError } from '../providers/csvSeed.js';
import { assertNotAborted, bump, type JobContext, type PipelineResult, type Stats } from './context.js';

export function parseCandidateCursor(cursor: string | null): number {
  const m = /^cand:(\d+)$/.exec(cursor ?? '');
  return m ? Number(m[1]) : 0;
}

export async function runAiReview(ctx: JobContext): Promise<PipelineResult> {
  const ai = ctx.ai;
  if (!ai) throw new ProviderError('AI is not configured on this worker (OPENAI_API_KEY / OPENAI_MODEL)');
  const stats: Stats = { ...(ctx.job.stats ?? {}) };
  let processed = ctx.job.processedCount;
  let after = parseCandidateCursor(ctx.job.cursor);
  let rowsThisRun = 0;

  for (;;) {
    assertNotAborted(ctx.signal);
    const batch = await ctx.api.reviewCandidates(ctx.job, after, ctx.config.batchSize);
    if (batch.length === 0) break;
    const notes: CandidateNoteInput[] = [];
    for (const c of batch) {
      assertNotAborted(ctx.signal);
      if (!c.suggested) {
        bump(stats, 'candidates_without_suggestion');
        continue;
      }
      try {
        const v = await reviewMatch(ai, c);
        notes.push({ candidate_id: c.id, verdict: v.verdict, confidence: v.confidence, reason: v.reason, model: `${v.model} (${MATCH_PROMPT_VERSION})` });
        bump(stats, `ai_verdict_${v.verdict}`);
      } catch (err) {
        if (err instanceof AiError && err.kind !== 'network' && err.kind !== 'http') {
          bump(stats, 'ai_rejected_outputs');
          ctx.log('warning', 'ai', `No usable AI verdict for candidate #${c.id}: ${err.message}`);
          continue;
        }
        throw err;
      }
    }
    if (notes.length > 0) {
      for (const r of await ctx.api.candidateNotes(ctx.job, notes)) {
        if (isItemError(r)) {
          bump(stats, 'notes_rejected');
          ctx.log('warning', 'ai', `Note rejected: ${r.error.message}.`);
        } else {
          bump(stats, 'notes_stored');
        }
      }
    }
    after = (batch[batch.length - 1] as { id: number }).id;
    processed += batch.length;
    rowsThisRun += batch.length;
    stats.ai_calls = ai.calls;
    await ctx.checkpoint(`cand:${after}`, processed, stats);
    ctx.afterCheckpoint(rowsThisRun);
  }
  ctx.log('info', 'summary', `Reviewed ${processed} candidates with AI (advisory notes only).`, stats);
  return { cursor: `cand:${after}`, processed, stats };
}
