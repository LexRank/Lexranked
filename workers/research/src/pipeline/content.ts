/**
 * content_generation: drafts ranking page text (summary, sections, FAQ)
 * from numbered facts, runs deterministic QA (+ optional AI QA) and stores
 * the result as a WordPress content DRAFT with its QA report. Nothing is
 * published; an editor applies drafts on the draft screen.
 *
 * Params: rankings?: number[] (default: every published, non-thin ranking),
 *         ai_qa?: boolean (default true).
 * Cursor: "ranking:<n>" = last ranking ID processed.
 */

import { AiError } from '../ai/openai.js';
import { aiReviewContent } from '../content/aiQa.js';
import { buildRankingFacts, type RankingData } from '../content/facts.js';
import { CONTENT_PROMPT_VERSION, generateRankingContent, unitsOf } from '../content/generate.js';
import { checkContent, type Issue } from '../content/qa.js';
import { ProviderError } from '../providers/csvSeed.js';
import { assertNotAborted, bump, type JobContext, type PipelineResult, type Stats } from './context.js';

/** Fewer ranked entries than this: no page worth writing (no thin content). */
export const MIN_ENTRIES_FOR_CONTENT = 3;

export function parseRankingCursor(cursor: string | null): number {
  const m = /^ranking:(\d+)$/.exec(cursor ?? '');
  return m ? Number(m[1]) : 0;
}

async function rankingIds(ctx: JobContext): Promise<number[]> {
  const wanted = ctx.job.params.rankings;
  if (Array.isArray(wanted)) {
    const ids = wanted.map(Number).filter((n) => Number.isInteger(n) && n > 0);
    if (ids.length === 0) throw new ProviderError('params.rankings must be a list of ranking IDs');
    return [...new Set(ids)].sort((a, b) => a - b);
  }
  const ids: number[] = [];
  for (let page = 1; page <= 50; page++) {
    const batch = await ctx.api.getPublic<{ id: number }[]>(`/rankings?per_page=100&page=${page}`);
    ids.push(...batch.map((r) => r.id));
    if (batch.length < 100) break;
  }
  return [...new Set(ids)].sort((a, b) => a - b);
}

export async function runContent(ctx: JobContext): Promise<PipelineResult> {
  const ai = ctx.ai;
  if (!ai) throw new ProviderError('AI is not configured on this worker (OPENAI_API_KEY / OPENAI_MODEL)');
  const stats: Stats = { ...(ctx.job.stats ?? {}) };
  let processed = ctx.job.processedCount;
  let after = parseRankingCursor(ctx.job.cursor);
  let rowsThisRun = 0;
  const useAiQa = ctx.job.params.ai_qa !== false;

  for (const id of (await rankingIds(ctx)).filter((n) => n > after)) {
    assertNotAborted(ctx.signal);
    const ranking = await ctx.api.getPublic<RankingData>(`/rankings/${id}`);
    if (ranking.isThin || ranking.entries.length < MIN_ENTRIES_FOR_CONTENT) {
      bump(stats, 'rankings_skipped_thin');
      ctx.log('info', 'content', `Ranking #${id} skipped: fewer than ${MIN_ENTRIES_FOR_CONTENT} ranked entries.`);
    } else {
      const facts = buildRankingFacts(ranking);
      try {
        const { content, model } = await generateRankingContent(ai, ranking, facts);
        const units = unitsOf(content);
        const issues: Issue[] = checkContent(units, facts, ranking);
        if (useAiQa) {
          try {
            issues.push(...(await aiReviewContent(ai, units, facts)));
          } catch (err) {
            if (!(err instanceof AiError) || err.kind === 'network' || err.kind === 'http') throw err;
            issues.push({ code: 'ai_qa_unavailable', severity: 'warning', message: `AI review produced no usable output: ${err.message}`, excerpt: '' });
          }
        }
        const hasError = issues.some((i) => i.severity === 'error');
        const res = await ctx.api.contentDraft(ctx.job, {
          content_type: 'ranking_content',
          target_id: id,
          content: {
            summary: content.summary,
            sections: content.sections.map((s) => ({ heading: s.heading, paragraphs: s.paragraphs.map((p) => ({ text: p.text })) })),
            faq: content.faq.map((f) => ({ question: f.question, answer: f.answer })),
          },
          facts: facts.map(({ id: fid, label, value }) => ({ id: fid, label, value })),
          qa: { status: hasError ? 'needs_review' : 'ready_for_review', issues },
          model,
          prompt_version: CONTENT_PROMPT_VERSION,
        });
        bump(stats, res.qaStatus === 'ready_for_review' ? 'drafts_ready' : 'drafts_need_review');
        ctx.log('info', 'content', `Draft #${res.draftId} for ranking #${id}: ${res.qaStatus} (${issues.length} QA issue(s)).`);
      } catch (err) {
        if (err instanceof AiError && err.kind !== 'network' && err.kind !== 'http' && err.kind !== 'budget') {
          bump(stats, 'ai_rejected_outputs');
          ctx.log('warning', 'content', `No usable draft for ranking #${id}: ${err.message}`);
        } else {
          throw err;
        }
      }
    }
    after = id;
    processed += 1;
    rowsThisRun += 1;
    stats.ai_calls = ai.calls;
    await ctx.checkpoint(`ranking:${after}`, processed, stats);
    ctx.afterCheckpoint(rowsThisRun);
  }
  ctx.log('info', 'summary', `Content drafts done for ${processed} ranking(s).`, stats);
  return { cursor: `ranking:${after}`, processed, stats };
}
