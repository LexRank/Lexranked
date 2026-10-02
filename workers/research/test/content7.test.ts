import { describe, expect, it } from 'vitest';
import { ResearchApi } from '../src/api.js';
import type { WorkerConfig } from '../src/config.js';
import { buildHubFacts, buildMethodologyFacts, buildProfileFacts, type EntitySummary, type HubData, type ProfileData } from '../src/content/facts.js';
import { articleSchema, profileSchema, unitsOf } from '../src/content/generate.js';
import { checkContent, type QaContext } from '../src/content/qa.js';
import { SafeFetcher } from '../src/fetcher.js';
import { JsonLogger } from '../src/logger.js';
import { runOnce } from '../src/runner.js';
import { FakeAi } from './fakeAi.js';
import { FakeWordPress } from './fakeWordPress.js';

const lawyer = (id: number, name: string, score: number, verified = true, isDemo = false): EntitySummary => ({
  id,
  name,
  type: 'lawyer',
  location: { city: 'Miami', state: 'Florida', stateCode: 'FL' },
  practiceAreas: [{ name: 'Personal Injury' }],
  rating: 4.5,
  reviewCount: 40,
  ranking: { score },
  verification: { status: verified ? 'verified' : 'pending' },
  isDemo,
});

const HUB: HubData = { kind: 'city', id: 7, slug: 'miami', name: 'Miami', stateName: 'Florida', lawyerCount: 3, lawFirmCount: 1, content: null };
const LAWYERS = [lawyer(1, 'Jordan Sample', 75.54), lawyer(2, 'Casey Fixture', 71.2), lawyer(3, 'Riley Placeholder', 64.03, false)];

const PROFILE: ProfileData = {
  id: 1,
  type: 'lawyer',
  name: 'Jordan Sample',
  title: 'Partner',
  firm: { name: 'Sample & Fixture, P.A.' },
  location: { city: 'Miami', state: 'Florida', stateCode: 'FL' },
  practiceAreas: [{ name: 'Personal Injury' }],
  rating: 4.8,
  reviewCount: 120,
  ranking: { score: 75.54, scoreVersion: 'v1.0' },
  verification: { status: 'verified' },
  professional: { yearsExperience: 14, barState: 'FL', barStatus: 'active', languages: ['English', 'Spanish'] },
  rankings: [{ title: 'Best Personal Injury Lawyers in Miami, Florida', position: 1 }],
  isDemo: false,
};

const METHODOLOGY = { active: 'v1.0', versions: [{ id: 'v1.0', weights: [{ label: 'Reputation', weight: 30 }, { label: 'Review strength', weight: 20 }] }] };

describe('Phase 7 facts', () => {
  it('builds hub facts from published profiles only', () => {
    const facts = buildHubFacts(HUB, LAWYERS, [{ title: 'Best PI Lawyers in Miami', entryCount: 3 }]);
    expect(facts[0]).toMatchObject({ id: 'F1', label: 'Location', value: 'Miami, Florida' });
    expect(facts.find((f) => f.label === 'Verified among the highest-scoring profiles shown')?.value).toBe('2 of 3');
    expect(facts.filter((f) => f.kind === 'entry').map((f) => f.name)).toEqual(['Jordan Sample', 'Casey Fixture', 'Riley Placeholder']);
  });

  it('builds profile facts without inventing missing fields', () => {
    const facts = buildProfileFacts(PROFILE);
    expect(facts.map((f) => f.label)).toContain('Years of experience');
    const sparse = buildProfileFacts({ ...PROFILE, title: null, firm: null, professional: undefined, rating: null, reviewCount: null, rankings: [] });
    expect(sparse.map((f) => f.label)).not.toContain('Years of experience');
    expect(sparse.map((f) => f.label)).not.toContain('Client rating');
  });

  it('numbers methodology facts after existing ones', () => {
    expect(buildMethodologyFacts(METHODOLOGY, 5)[0]?.id).toBe('F5');
    expect(buildMethodologyFacts(METHODOLOGY).find((f) => f.label === 'Score components and weights')?.value).toBe('Reputation 30%, Review strength 20%');
  });

  it('constrains profile and article schemas', () => {
    expect(profileSchema(['F1']).properties?.summaryFactRefs?.minItems).toBe(1);
    expect(articleSchema(['F1']).properties?.sections?.minItems).toBe(2);
  });
});

describe('article QA', () => {
  const facts = buildMethodologyFacts(METHODOLOGY);
  const ctx: QaContext = { keyword: '', existingText: '', calculatedAt: null, isDemo: false, refsRequired: false, minWords: 0 };
  const article = (text: string, refs: string[] = []) => ({
    title: 'How to choose a lawyer',
    summary: 'What to check before you hire.',
    summaryFactRefs: [],
    sections: [
      { heading: 'Check credentials', paragraphs: [{ text, factRefs: refs }] },
      { heading: 'Ask questions', paragraphs: [{ text: 'Ask who will handle your case day to day and how you will be updated.', factRefs: [] }] },
    ],
    faq: [],
  });

  it('allows uncited general guidance', () => {
    expect(checkContent(unitsOf(article('Confirm that the lawyer is licensed in your state before you sign anything.')), facts, ctx).filter((i) => i.severity === 'error')).toEqual([]);
  });

  it('forbids numbers and names in uncited guidance', () => {
    const codes = checkContent(unitsOf(article('Most firms win 87% of cases.')), facts, ctx).map((i) => i.code);
    expect(codes).toContain('unsupported_number');
  });

  it('accepts cited methodology numbers', () => {
    const errors = checkContent(unitsOf(article('Reputation carries 30% of the LexRank score.', ['F3'])), facts, ctx).filter((i) => i.severity === 'error');
    expect(errors).toEqual([]);
  });
});

describe('content_generation kinds', () => {
  function setup(ai: FakeAi) {
    const wp = new FakeWordPress();
    const config: WorkerConfig = {
      apiUrl: 'http://wp.test/wp-json/lexranked/v1', user: 'w', appPassword: 'p', workerId: 't', jobTypes: ['content_generation'],
      dataDir: 'fixtures/datasets', userAgent: 'x', pollSeconds: 1, heartbeatSeconds: 3600, fetchTimeoutMs: 1000, fetchMaxBytes: 1000,
      perHostIntervalMs: 0, allowPrivateNetwork: false, batchSize: 2, crashAfterRows: null,
      ai: { apiKey: 'k', model: 'fake-model', baseUrl: 'https://api.openai.com/v1', maxCallsPerJob: 50, timeoutMs: 1000 },
    };
    const api = new ResearchApi({ baseUrl: config.apiUrl, user: 'w', appPassword: 'p', userAgent: 'x', fetchImpl: wp.fetch, sleep: async () => {} });
    const fetcher = new SafeFetcher({ userAgent: 'x', timeoutMs: 1, maxBytes: 1, perHostIntervalMs: 0, allowPrivateNetwork: false });
    return { wp, deps: { api, config, fetcher, ai, logger: new JsonLogger(() => {}), shutdown: new AbortController().signal } };
  }
  const qa = { content_qa: () => ({ issues: [] }) };
  const refsFor = (req: { user: string }, label: string): string => (JSON.parse(req.user).facts as { id: string; label: string }[]).find((f) => f.label === label)?.id as string;

  it('drafts hub content only for hubs with enough profiles', async () => {
    const ai = new FakeAi({
      ...qa,
      hub_content: (req) => ({
        summary: 'Miami, Florida has 3 published lawyer profiles on LexRanked.',
        summaryFactRefs: [refsFor(req, 'Location'), refsFor(req, 'Published lawyer profiles')],
        sections: [{ heading: 'How profiles are ordered', paragraphs: [{ text: 'Profiles are ordered by the LexRank score; payment never affects positions.', factRefs: [refsFor(req, 'How profiles are ordered')] }] }],
        faq: [],
      }),
    });
    const { wp, deps } = setup(ai);
    wp.publicRoutes = {
      '/states': [],
      '/cities': [{ ...HUB, state: { name: 'Florida' } }, { id: 8, slug: 'tampa', name: 'Tampa', lawyerCount: 1, lawFirmCount: 0, state: { name: 'Florida' } }],
      '/practice-areas': [],
      '/lawyers': LAWYERS,
      '/rankings': [],
    };
    const job = wp.addJob('content_generation', { kind: 'hub' });
    expect(await runOnce(deps)).toBe('completed');
    expect(wp.drafts).toHaveLength(1);
    expect(wp.drafts[0]).toMatchObject({ content_type: 'hub_content', target_term: 7, target_taxonomy: 'lr_location', prompt_version: 'interp/1+hub-content/2' });
    expect(job.stats).toMatchObject({ targets_skipped_thin: 1 });
  });

  it('drafts profile summaries and flags invented facts', async () => {
    const ai = new FakeAi({
      ...qa,
      profile_summary: (req) => ({
        summary: 'Jordan Sample is a Partner at Sample & Fixture, P.A. in Miami with 14 years of experience and 300 trials won.',
        summaryFactRefs: [refsFor(req, 'Name'), refsFor(req, 'Professional title'), refsFor(req, 'Years of experience')],
      }),
    });
    const { wp, deps } = setup(ai);
    wp.publicRoutes = { '/lawyers': [{ id: 1 }], '/law-firms': [], '/lawyers/1': PROFILE };
    wp.addJob('content_generation', { kind: 'profile' });
    expect(await runOnce(deps)).toBe('completed');
    const draft = wp.drafts[0] as { content_type: string; target_id: number; qaStatus: string; content: { sections: unknown[] } };
    expect(draft).toMatchObject({ content_type: 'profile_summary', target_id: 1, qaStatus: 'needs_review' });
    expect(draft.content.sections).toEqual([]);
  });

  it('drafts an article from a topic and methodology facts', async () => {
    const ai = new FakeAi({
      ...qa,
      article_draft: (req) => ({
        title: 'How to read a lawyer ranking',
        summary: 'What the LexRank score measures and what to check yourself.',
        summaryFactRefs: [refsFor(req, 'How positions are decided')],
        sections: [
          { heading: 'What the score measures', paragraphs: [{ text: 'Reputation carries 30% of the score under methodology v1.0.', factRefs: [refsFor(req, 'Score components and weights'), refsFor(req, 'Methodology version')] }], bullets: [] },
          { heading: 'What to check yourself', paragraphs: [{ text: 'Ask who will handle your case day to day and how fees work.', factRefs: [] }], bullets: [{ text: 'Who will handle your case day to day', factRefs: [] }, { text: 'How fees and costs are charged', factRefs: [] }] },
        ],
        faq: [],
      }),
    });
    const { wp, deps } = setup(ai);
    wp.publicRoutes = { '/score-versions': METHODOLOGY };
    wp.addJob('content_generation', { kind: 'article', topic: 'How to read a lawyer ranking' });
    expect(await runOnce(deps)).toBe('completed');
    expect(wp.drafts[0]).toMatchObject({ content_type: 'article', target_id: null, content: { title: 'How to read a lawyer ranking' }, prompt_version: 'interp/1+article/3' });
    expect((wp.drafts[0] as { content: { sections: unknown[] } }).content.sections[1]).toMatchObject({ bullets: ['Who will handle your case day to day', 'How fees and costs are charged'] });
  });

  it('rejects an article job without a usable topic', async () => {
    const { wp, deps } = setup(new FakeAi({}));
    const job = wp.addJob('content_generation', { kind: 'article', topic: 'short' });
    expect(await runOnce(deps)).toBe('failed');
    expect(job).toMatchObject({ retryable: false });
  });
});
