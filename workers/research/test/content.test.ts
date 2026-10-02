import { describe, expect, it } from 'vitest';
import { ResearchApi } from '../src/api.js';
import type { WorkerConfig } from '../src/config.js';
import { buildRankingFacts, type RankingData } from '../src/content/facts.js';
import { contentSchema, unitsOf, type Generated } from '../src/content/generate.js';
import { checkContent, similarity } from '../src/content/qa.js';
import { SafeFetcher } from '../src/fetcher.js';
import { JsonLogger } from '../src/logger.js';
import { runOnce } from '../src/runner.js';
import { FakeAi } from './fakeAi.js';
import { FakeWordPress } from './fakeWordPress.js';

const entity = (id: number, name: string, city: string, verified: boolean, rating: number | null, reviews: number | null) => ({
  id,
  name,
  type: 'lawyer' as const,
  location: { city, state: 'Florida', stateCode: 'FL' },
  rating,
  reviewCount: reviews,
  verification: { status: verified ? 'verified' : 'pending' },
  firm: null,
});

export const RANKING: RankingData = {
  id: 42,
  title: 'Best Personal Injury Lawyers in Miami, Florida',
  entityType: 'lawyer',
  location: { city: 'Miami', state: 'Florida', stateCode: 'FL' },
  practiceArea: { slug: 'personal-injury', name: 'Personal Injury' },
  scoreVersion: 'v1.0',
  entryCount: 3,
  minEntities: 3,
  isThin: false,
  isDemo: false,
  calculatedAt: '2026-09-20T10:00:00Z',
  methodologyUrl: 'https://lexranked.com/methodology/',
  summary: null,
  body: '',
  faq: [],
  entries: [
    { position: 1, score: 75.54, movement: 1, isNew: false, entity: entity(1, 'Jordan Sample', 'Miami', true, 4.8, 120) },
    { position: 2, score: 71.2, movement: -1, isNew: false, entity: entity(2, 'Casey Fixture', 'Miami', true, null, null) },
    { position: 3, score: 64.03, movement: null, isNew: true, entity: entity(3, 'Riley Placeholder', 'Coral Gables', false, 4.5, 12) },
  ],
};

const GOOD: Generated = {
  summary: 'Jordan Sample is #1 of 3 ranked personal injury lawyers in Miami, Florida, with a LexRank score of 75.54.',
  summaryFactRefs: ['F5', 'F10'],
  sections: [
    {
      heading: 'Who leads the ranking',
      paragraphs: [
        { text: 'Jordan Sample holds position 1 with a LexRank score of 75.54 and a 4.8 rating from 120 reviews. Casey Fixture follows at #2 with a score of 71.20.', factRefs: ['F10', 'F11'] },
        { text: 'Riley Placeholder is new in this ranking at #3; that profile is still awaiting verification, while 2 of 3 ranked profiles are verified.', factRefs: ['F12', 'F9'] },
      ],
    },
    {
      heading: 'How positions are decided',
      paragraphs: [
        { text: 'Positions follow the LexRank score under methodology v1.0, a deterministic score from verified data, sources, experience and reviews. Payment never affects positions, and the ranking was last calculated on 2026-09-20.', factRefs: ['F6', 'F7', 'F8'] },
      ],
    },
  ],
  faq: [
    { question: 'How many lawyers are ranked?', answer: 'The ranking currently lists 3 personal injury lawyers in Miami, Florida.', factRefs: ['F5', 'F2', 'F3'] },
    { question: 'Can a lawyer pay to rank higher?', answer: 'No. Positions follow the LexRank score and payment never affects positions.', factRefs: ['F7'] },
  ],
};

const NOW = new Date('2026-09-26T00:00:00Z');

describe('ranking facts', () => {
  it('are deterministic and numbered', () => {
    const facts = buildRankingFacts(RANKING);
    expect(facts).toEqual(buildRankingFacts(structuredClone(RANKING)));
    expect(facts.map((f) => f.id)).toEqual(facts.map((_, i) => `F${i + 1}`));
    expect(facts.find((f) => f.id === 'F10')).toMatchObject({ kind: 'entry', position: 1, name: 'Jordan Sample' });
    expect(facts.find((f) => f.id === 'F10')?.value).toBe('#1 Jordan Sample; LexRank score 75.54; based in Miami, FL; rated 4.8 from 120 reviews; verified by LexRanked; up 1 since the previous calculation');
    expect(facts.find((f) => f.id === 'F9')?.value).toBe('2 of 3');
  });

  it('constrain fact references in the schema', () => {
    const schema = contentSchema(['F1', 'F2']);
    expect(schema.properties?.summaryFactRefs?.items?.enum).toEqual(['F1', 'F2']);
  });
});

describe('content QA', () => {
  const facts = buildRankingFacts(RANKING);
  const codes = (g: Generated) => checkContent(unitsOf(g), facts, RANKING, NOW).map((i) => `${i.severity}:${i.code}`);

  it('passes grounded content', () => {
    expect(codes(GOOD).filter((c) => c.startsWith('error'))).toEqual([]);
  });

  it('catches wrong positions, invented numbers, promises, links and missing references', () => {
    const bad: Generated = structuredClone(GOOD);
    (bad.sections[0]!.paragraphs[0]!).text = 'Casey Fixture is ranked #1 with 25 years of experience.';
    (bad.sections[0]!.paragraphs[1]!).factRefs = [];
    bad.faq[1]!.answer = 'We guarantee results; see https://example.com.';
    const found = codes(bad);
    expect(found).toContain('error:wrong_position');
    expect(found).toContain('error:unsupported_number');
    expect(found).toContain('error:missing_fact_refs');
    expect(found).toContain('error:forbidden_claim');
    expect(found).toContain('error:link_in_text');
  });

  it('warns about stuffing, duplicates, promotional wording, stale and demo data', () => {
    const stuffed: Generated = structuredClone(GOOD);
    stuffed.summary = 'Personal injury Miami lawyers: the best personal injury Miami choice. ' + 'Personal injury Miami. '.repeat(7);
    stuffed.sections.push(structuredClone(GOOD.sections[1]!));
    const found = checkContent(unitsOf(stuffed), facts, { ...RANKING, isDemo: true, calculatedAt: '2026-01-01T00:00:00Z' }, NOW).map((i) => i.code);
    expect(found).toEqual(expect.arrayContaining(['keyword_stuffing', 'duplicate_sentence', 'promotional_language', 'outdated_data', 'demo_data']));
  });

  it('measures overlap with the existing page', () => {
    expect(similarity('one two three four five six seven', 'zero one two three four five six seven')).toBe(1);
    expect(similarity('alpha beta gamma delta epsilon', 'one two three four five')).toBe(0);
  });
});

describe('content_generation and ai_candidate_review pipelines', () => {
  function setup(ai: FakeAi) {
    const wp = new FakeWordPress();
    const config: WorkerConfig = {
      apiUrl: 'http://wp.test/wp-json/lexranked/v1', user: 'w', appPassword: 'p', workerId: 't', jobTypes: ['content_generation', 'ai_candidate_review'],
      dataDir: 'fixtures/datasets', userAgent: 'LexRankedBot/0.5', pollSeconds: 1, heartbeatSeconds: 3600, fetchTimeoutMs: 1000, fetchMaxBytes: 1000,
      perHostIntervalMs: 0, allowPrivateNetwork: false, batchSize: 2, crashAfterRows: null,
      ai: { apiKey: 'k', model: 'fake-model', baseUrl: 'https://api.openai.com/v1', maxCallsPerJob: 50, timeoutMs: 1000 },
    };
    const api = new ResearchApi({ baseUrl: config.apiUrl, user: 'w', appPassword: 'p', userAgent: 'x', fetchImpl: wp.fetch, sleep: async () => {} });
    const fetcher = new SafeFetcher({ userAgent: 'x', timeoutMs: 1, maxBytes: 1, perHostIntervalMs: 0, allowPrivateNetwork: false });
    return { wp, deps: { api, config, fetcher, ai, logger: new JsonLogger(() => {}), shutdown: new AbortController().signal } };
  }

  it('drafts content for non-thin rankings with a QA report and never publishes', async () => {
    const ai = new FakeAi({ ranking_content: () => GOOD, content_qa: () => ({ issues: [{ code: 'unnatural_language', severity: 'warning', excerpt: 'holds position 1', message: 'stiff' }, { code: 'unsupported_claim', severity: 'error', excerpt: 'not in the draft', message: 'x' }] }) });
    const { wp, deps } = setup(ai);
    wp.rankings = [RANKING, { ...RANKING, id: 43, isThin: true, entries: RANKING.entries.slice(0, 1) }];
    const job = wp.addJob('content_generation', {});
    expect(await runOnce(deps)).toBe('completed');
    expect(job.cursor).toBe('after:ranking:0000000043');
    expect(job.stats).toMatchObject({ drafts_ready: 1, targets_skipped_thin: 1, ai_calls: 2 });
    expect(wp.drafts).toHaveLength(1);
    const draft = wp.drafts[0] as { target_id: number; qaStatus: string; qa: { issues: { code: string }[] }; facts: unknown[]; prompt_version: string };
    expect(draft.target_id).toBe(42);
    expect(draft.qaStatus).toBe('ready_for_review');
    // The AI reviewer's issue with an excerpt that is not in the draft is dropped.
    expect(draft.qa.issues.map((i) => i.code)).toEqual(['ai_unnatural_language']);
    expect(draft.facts.length).toBe(12);
    expect(draft.prompt_version).toBe('interp/1+ranking-content/2');
  });

  it('marks drafts with invented facts as needs_review', async () => {
    const bad = structuredClone(GOOD);
    bad.summary = 'Jordan Sample has won 300 cases.';
    const { wp, deps } = setup(new FakeAi({ ranking_content: () => bad, content_qa: () => ({ issues: [] }) }));
    wp.rankings = [RANKING];
    wp.addJob('content_generation', {});
    await runOnce(deps);
    expect((wp.drafts[0] as { qaStatus: string }).qaStatus).toBe('needs_review');
  });

  it('skips schema-violating model output without failing the job', async () => {
    const { wp, deps } = setup(new FakeAi({ ranking_content: () => ({ summary: 1 }) }));
    wp.rankings = [RANKING];
    const job = wp.addJob('content_generation', {});
    expect(await runOnce(deps)).toBe('completed');
    expect(wp.drafts).toHaveLength(0);
    expect(job.stats).toMatchObject({ ai_rejected_outputs: 1 });
  });

  it('stores advisory AI notes for candidates in review', async () => {
    const { wp, deps } = setup(new FakeAi({ match_review: () => ({ verdict: 'unsure', confidence: 0.4, reason: 'Different cities.' }) }));
    wp.reviewQueue = [
      { id: 7, entityType: 'lawyer', name: 'Jane Doe', city: 'Miami', state: 'FL', practiceArea: null, website: null, sourceUrl: 'https://s.test', reason: 'x', suggested: { id: 3, name: 'Jane Doe', status: 'publish', city: 'Tampa', state: 'FL', website: null, practiceAreas: [] } },
      { id: 8, entityType: 'lawyer', name: 'Solo Person', city: null, state: null, practiceArea: null, website: null, sourceUrl: 'https://s.test', reason: 'x', suggested: null },
    ];
    const job = wp.addJob('ai_candidate_review', {});
    expect(await runOnce(deps)).toBe('completed');
    expect(wp.notes).toEqual([{ candidate_id: 7, verdict: 'unsure', confidence: 0.4, reason: 'Different cities.', model: 'fake-model (match-review/1)' }]);
    expect(job.cursor).toBe('cand:8');
  });

  it('fails permanently when AI is not configured', async () => {
    const { wp, deps } = setup(new FakeAi({}));
    const job = wp.addJob('content_generation', {});
    expect(await runOnce({ ...deps, ai: null })).toBe('failed');
    expect(job).toMatchObject({ status: 'failed', retryable: false });
  });
});
