/**
 * content_generation: drafts editorial text from numbered facts, runs
 * deterministic QA (+ optional AI QA) and stores each result as a WordPress
 * content DRAFT with its QA report. Nothing is published; editors apply
 * drafts on the draft screen.
 *
 * Params:
 *   kind:     "ranking" (default) | "hub" | "profile" | "article"
 *   rankings: number[]  (kind ranking; default: every published, non-thin ranking)
 *   hubs:     "all" | "state" | "city" | "practice_area" (kind hub; default all)
 *   entities: number[]  (kind profile; default: every published lawyer and firm)
 *   topic:    string    (kind article, required) · ranking: number (optional context)
 *   ai_qa:    boolean   (default true)
 * Cursor: "after:<target key>" — targets are processed in key order.
 */

import type { ContentDraftInput } from '../api.js';
import { AiError, type AiClient } from '../ai/openai.js';
import { promptVersion } from '../ai/interpretation.js';
import { aiReviewContent } from '../content/aiQa.js';
import {
  buildHubFacts,
  buildMethodologyFacts,
  buildProfileFacts,
  buildRankingFacts,
  hubPlace,
  type EntitySummary,
  type Fact,
  type HubData,
  type MarketData,
  type MethodologyData,
  type ProfileData,
  type RankingData,
} from '../content/facts.js';
import {
  ARTICLE_PROMPT_VERSION,
  CONTENT_PROMPT_VERSION,
  generateArticle,
  generateHubContent,
  generateProfileSummary,
  generateRankingContent,
  HUB_PROMPT_VERSION,
  PROFILE_PROMPT_VERSION,
  unitsOf,
  type Generated,
} from '../content/generate.js';
import { checkContent, rankingQaContext, type Issue, type QaContext } from '../content/qa.js';
import { ProviderError } from '../providers/csvSeed.js';
import { assertNotAborted, bump, type JobContext, type PipelineResult, type Stats } from './context.js';

/** Fewer ranked entries / profiles than this: no page worth writing (no thin content). */
export const MIN_ENTRIES_FOR_CONTENT = 3;

/** A profile summary needs at least this many facts beyond name and type. */
export const MIN_PROFILE_FACTS = 5;

export type ContentKind = 'ranking' | 'hub' | 'profile' | 'article';

interface Target {
  /** Sortable, unique key (zero-padded IDs). */
  key: string;
  label: string;
  build(ai: AiClient): Promise<Draft | null>;
}

interface Draft {
  payload: Omit<ContentDraftInput, 'qa' | 'model' | 'prompt_version' | 'facts' | 'content'>;
  generated: Generated & { title?: string };
  facts: Fact[];
  qa: QaContext;
  model: string;
  promptVersion: string;
}

const pad = (n: number): string => String(n).padStart(10, '0');

/**
 * Market statistics computed by the backend (Etap I). Optional: an older API
 * or an unknown slug gives null, and the facts fall back to page data.
 */
async function marketFor(ctx: JobContext, query: string): Promise<MarketData | null> {
  try {
    return await ctx.api.getPublic<MarketData>(`/market?${query}`);
  } catch {
    return null;
  }
}

export function parseAfterCursor(cursor: string | null): string {
  const m = /^after:(.+)$/.exec(cursor ?? '');
  return m ? (m[1] as string) : '';
}

function kindOf(params: Record<string, unknown>): ContentKind {
  const kind = params.kind ?? 'ranking';
  if (kind === 'ranking' || kind === 'hub' || kind === 'profile' || kind === 'article') return kind;
  throw new ProviderError('params.kind must be ranking, hub, profile or article');
}

function ids(value: unknown, name: string): number[] | null {
  if (value === undefined) return null;
  if (!Array.isArray(value)) throw new ProviderError(`params.${name} must be a list of IDs`);
  const list = value.map(Number).filter((n) => Number.isInteger(n) && n > 0);
  if (list.length === 0) throw new ProviderError(`params.${name} must be a list of IDs`);
  return [...new Set(list)];
}

async function allPages<T>(ctx: JobContext, path: string): Promise<T[]> {
  const out: T[] = [];
  const sep = path.includes('?') ? '&' : '?';
  for (let page = 1; page <= 50; page++) {
    const batch = await ctx.api.getPublic<T[]>(`${path}${sep}per_page=100&page=${page}`);
    out.push(...batch);
    if (batch.length < 100) break;
  }
  return out;
}

// ─── Targets per kind ────────────────────────────────────────────────────

async function rankingTargets(ctx: JobContext): Promise<Target[]> {
  const wanted = ids(ctx.job.params.rankings, 'rankings') ?? (await allPages<{ id: number }>(ctx, '/rankings')).map((r) => r.id);
  return [...new Set(wanted)].map((id) => ({
    key: `ranking:${pad(id)}`,
    label: `ranking #${id}`,
    build: async (ai) => {
      const ranking = await ctx.api.getPublic<RankingData>(`/rankings/${id}`);
      if (ranking.isThin || ranking.entries.length < MIN_ENTRIES_FOR_CONTENT) return null;
      const place = ranking.location?.citySlug ?? ranking.location?.stateSlug ?? null;
      const market = place ? await marketFor(ctx, `location=${place}${ranking.practiceArea ? `&practice_area=${ranking.practiceArea.slug}` : ''}`) : null;
      const facts = buildRankingFacts(ranking, market);
      const { content, model } = await generateRankingContent(ai, ranking, facts);
      return { payload: { content_type: 'ranking_content', target_id: id }, generated: content, facts, qa: rankingQaContext(ranking), model, promptVersion: promptVersion(CONTENT_PROMPT_VERSION) };
    },
  }));
}

async function hubTargets(ctx: JobContext): Promise<Target[]> {
  const which = (ctx.job.params.hubs as string | undefined) ?? 'all';
  if (!['all', 'state', 'city', 'practice_area'].includes(which)) throw new ProviderError('params.hubs must be all, state, city or practice_area');
  type Raw = { id: number; slug: string; name: string; lawyerCount: number; lawFirmCount: number; state?: { name: string | null }; content?: HubData['content'] };
  const hubs: HubData[] = [];
  const addAll = (kind: HubData['kind'], list: Raw[]): void => {
    for (const h of list) hubs.push({ kind, id: h.id, slug: h.slug, name: h.name, stateName: h.state?.name ?? null, lawyerCount: h.lawyerCount, lawFirmCount: h.lawFirmCount, content: h.content ?? null });
  };
  if (which === 'all' || which === 'state') addAll('state', await ctx.api.getPublic<Raw[]>('/states'));
  if (which === 'all' || which === 'city') addAll('city', await ctx.api.getPublic<Raw[]>('/cities'));
  if (which === 'all' || which === 'practice_area') addAll('practice_area', await ctx.api.getPublic<Raw[]>('/practice-areas'));

  return hubs.map((h) => ({
    key: `hub:${h.kind}:${pad(h.id)}`,
    label: `${h.kind.replace('_', ' ')} "${h.name}"`,
    build: async (ai) => {
      // Same rule as the site: a hub page exists only with enough published lawyers.
      if (h.lawyerCount < MIN_ENTRIES_FOR_CONTENT) return null;
      const filter = h.kind === 'practice_area' ? `practice_area=${h.slug}` : `${h.kind}=${h.slug}`;
      const lawyers = await ctx.api.getPublic<EntitySummary[]>(`/lawyers?${filter}&per_page=10&orderby=score&order=desc`);
      const rankingFilter = h.kind === 'practice_area' ? `practice_area=${h.slug}` : `location=${h.slug}`;
      const rankings = (await ctx.api.getPublic<{ title: string; entryCount: number; isThin: boolean }[]>(`/rankings?${rankingFilter}&per_page=20`)).filter((r) => !r.isThin);
      const market = await marketFor(ctx, h.kind === 'practice_area' ? `practice_area=${h.slug}` : `location=${h.slug}`);
      const facts = buildHubFacts(h, lawyers, rankings, market);
      const place = hubPlace(h);
      const { content, model } = await generateHubContent(ai, { title: h.kind === 'practice_area' ? `${h.name} lawyers` : `Lawyers in ${place}`, place }, facts);
      const existing = h.content ? [h.content.summary ?? '', h.content.body.replace(/<[^>]+>/g, ' '), ...h.content.faq.map((f) => `${f.question} ${f.answer}`)].join(' ') : '';
      return {
        payload: { content_type: 'hub_content', target_term: h.id, target_taxonomy: h.kind === 'practice_area' ? 'lr_practice_area' : 'lr_location' },
        generated: content,
        facts,
        qa: { keyword: place, existingText: existing, calculatedAt: null, isDemo: lawyers.length > 0 && lawyers.every((l) => l.isDemo), refsRequired: true, minWords: 120 },
        model,
        promptVersion: promptVersion(HUB_PROMPT_VERSION),
      };
    },
  }));
}

async function profileTargets(ctx: JobContext): Promise<Target[]> {
  const wanted = ids(ctx.job.params.entities, 'entities');
  const lawyers = await allPages<{ id: number }>(ctx, '/lawyers?orderby=score&order=desc');
  const firms = await allPages<{ id: number }>(ctx, '/law-firms?orderby=score&order=desc');
  const all: { type: 'lawyers' | 'law-firms'; id: number }[] = [...lawyers.map((l) => ({ type: 'lawyers' as const, id: l.id })), ...firms.map((f) => ({ type: 'law-firms' as const, id: f.id }))];
  return all
    .filter((e) => wanted === null || wanted.includes(e.id))
    .map((e) => ({
      key: `profile:${pad(e.id)}`,
      label: `profile #${e.id}`,
      build: async (ai) => {
        const p = await ctx.api.getPublic<ProfileData & { summary?: string | null }>(`/${e.type}/${e.id}`);
        const facts = buildProfileFacts({ ...p, type: e.type === 'law-firms' ? 'law_firm' : 'lawyer' });
        if (facts.length - 2 < MIN_PROFILE_FACTS) return null;
        const { content, model } = await generateProfileSummary(ai, facts);
        return {
          payload: { content_type: 'profile_summary', target_id: e.id },
          generated: { summary: content.summary, summaryFactRefs: content.summaryFactRefs, sections: [], faq: [] },
          facts,
          qa: { keyword: '', existingText: p.summary ?? '', calculatedAt: null, isDemo: p.isDemo, refsRequired: true, minWords: 0 },
          model,
          promptVersion: promptVersion(PROFILE_PROMPT_VERSION),
        };
      },
    }));
}

async function articleTargets(ctx: JobContext): Promise<Target[]> {
  const topic = ctx.job.params.topic;
  if (typeof topic !== 'string' || topic.trim().length < 10 || topic.length > 200) {
    throw new ProviderError('params.topic must be a 10–200 character brief for the article');
  }
  const rankingId = ctx.job.params.ranking === undefined ? null : Number(ctx.job.params.ranking);
  if (rankingId !== null && !(Number.isInteger(rankingId) && rankingId > 0)) throw new ProviderError('params.ranking must be a ranking ID');
  return [
    {
      key: 'article:0000000001',
      label: `article "${topic.trim()}"`,
      build: async (ai) => {
        let facts: Fact[] = [];
        let isDemo = false;
        let calculatedAt: string | null = null;
        if (rankingId !== null) {
          const ranking = await ctx.api.getPublic<RankingData>(`/rankings/${rankingId}`);
          if (ranking.isThin) throw new ProviderError('params.ranking points to a thin ranking');
          facts = buildRankingFacts(ranking);
          isDemo = ranking.isDemo;
          calculatedAt = ranking.calculatedAt;
        }
        const methodology = await ctx.api.getPublic<MethodologyData>('/score-versions');
        facts = [...facts, ...buildMethodologyFacts(methodology, facts.length + 1)];
        const { content, model } = await generateArticle(ai, topic.trim(), facts);
        return {
          payload: { content_type: 'article', target_id: rankingId },
          generated: content,
          facts,
          qa: { keyword: '', existingText: '', calculatedAt, isDemo, refsRequired: false, minWords: 300 },
          model,
          promptVersion: promptVersion(ARTICLE_PROMPT_VERSION),
        };
      },
    },
  ];
}

const TARGETS: Record<ContentKind, (ctx: JobContext) => Promise<Target[]>> = {
  ranking: rankingTargets,
  hub: hubTargets,
  profile: profileTargets,
  article: articleTargets,
};

// ─── Pipeline ────────────────────────────────────────────────────────────

export async function runContent(ctx: JobContext): Promise<PipelineResult> {
  const ai = ctx.ai;
  if (!ai) throw new ProviderError('AI is not configured on this worker (OPENAI_API_KEY / OPENAI_MODEL)');
  const kind = kindOf(ctx.job.params);
  const stats: Stats = { ...(ctx.job.stats ?? {}) };
  let processed = ctx.job.processedCount;
  let after = parseAfterCursor(ctx.job.cursor);
  let rowsThisRun = 0;
  const useAiQa = ctx.job.params.ai_qa !== false;

  const targets = (await TARGETS[kind](ctx)).sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : 0)).filter((t) => t.key > after);
  for (const target of targets) {
    assertNotAborted(ctx.signal);
    try {
      const draft = await target.build(ai);
      if (draft === null) {
        bump(stats, 'targets_skipped_thin');
        ctx.log('info', 'content', `Skipped ${target.label}: not enough data for a useful page.`);
      } else {
        const units = unitsOf(draft.generated);
        const issues: Issue[] = checkContent(units, draft.facts, draft.qa);
        if (useAiQa) {
          try {
            issues.push(...(await aiReviewContent(ai, units, draft.facts)));
          } catch (err) {
            if (!(err instanceof AiError) || err.kind === 'network' || err.kind === 'http') throw err;
            issues.push({ code: 'ai_qa_unavailable', severity: 'warning', message: `AI review produced no usable output: ${err.message}`, excerpt: '' });
          }
        }
        const g = draft.generated;
        const res = await ctx.api.contentDraft(ctx.job, {
          ...draft.payload,
          content: {
            ...(g.title ? { title: g.title } : {}),
            summary: g.summary,
            sections: g.sections.map((s) => ({
              heading: s.heading,
              paragraphs: s.paragraphs.map((p) => ({ text: p.text })),
              ...(s.bullets && s.bullets.length > 0 ? { bullets: s.bullets.map((b) => b.text) } : {}),
            })),
            faq: g.faq.map((f) => ({ question: f.question, answer: f.answer })),
          },
          facts: draft.facts.map(({ id, label, value, status, origin }) => ({ id, label, value, ...(status ? { status } : {}), ...(origin ? { origin } : {}) })),
          qa: { status: issues.some((i) => i.severity === 'error') ? 'needs_review' : 'ready_for_review', issues },
          model: draft.model,
          prompt_version: draft.promptVersion,
        });
        bump(stats, res.qaStatus === 'ready_for_review' ? 'drafts_ready' : 'drafts_need_review');
        ctx.log('info', 'content', `Draft #${res.draftId} for ${target.label}: ${res.qaStatus} (${issues.length} QA issue(s)).`);
      }
    } catch (err) {
      if (err instanceof AiError && err.kind !== 'network' && err.kind !== 'http' && err.kind !== 'budget') {
        bump(stats, 'ai_rejected_outputs');
        ctx.log('warning', 'content', `No usable draft for ${target.label}: ${err.message}`);
      } else {
        throw err;
      }
    }
    after = target.key;
    processed += 1;
    rowsThisRun += 1;
    stats.ai_calls = ai.calls;
    await ctx.checkpoint(`after:${after}`, processed, stats);
    ctx.afterCheckpoint(rowsThisRun);
  }
  ctx.log('info', 'summary', `Content drafts (${kind}) done for ${processed} target(s).`, stats);
  return { cursor: after === '' ? null : `after:${after}`, processed, stats };
}
