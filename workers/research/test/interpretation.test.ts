import { describe, expect, it } from 'vitest';
import { INTERPRETATION_RULES, interpretationSystem, promptVersion } from '../src/ai/interpretation.js';
import { buildHubFacts, buildProfileFacts, buildRankingFacts, type Fact, type HubData, type MarketData, type ProfileData, type RankingData } from '../src/content/facts.js';
import { checkContent, type QaContext, type Unit } from '../src/content/qa.js';

const market: MarketData = {
  scope: { location: { name: 'Miami, Florida' }, practiceArea: { name: 'Personal Injury' } },
  stats: {
    version: 'mkt-1.0',
    lawyers: 127,
    firms: 40,
    verifiedLawyers: 82,
    averageRating: { value: 4.68, sample: 120 },
    medianReviewCount: { value: 96, sample: 118 },
    mostCommonPractice: { name: 'Personal Injury', count: 127 },
    dataVerifiedAt: '2026-09-27T10:00:00Z',
    calculatedAt: '2026-09-28T00:00:00Z',
  },
};

const entity = (id: number, name: string, status = 'verified') => ({
  id,
  name,
  type: 'lawyer' as const,
  location: { city: 'Miami', state: 'Florida', stateCode: 'FL' },
  rating: 4.8,
  reviewCount: 200,
  verification: { status },
});

const ranking: RankingData = {
  id: 1,
  title: 'Best Car Accident Lawyers in Miami',
  entityType: 'lawyer',
  location: { city: 'Miami', state: 'Florida', stateCode: 'FL', citySlug: 'miami', stateSlug: 'florida' },
  practiceArea: { slug: 'personal-injury', name: 'Personal Injury' },
  scoreVersion: 'v1.1',
  entryCount: 3,
  minEntities: 3,
  isThin: false,
  isDemo: false,
  calculatedAt: '2026-09-28T00:00:00Z',
  methodologyUrl: '/methodology/',
  summary: null,
  body: '',
  faq: [],
  entries: [
    { position: 1, score: 84.03, movement: null, isNew: false, entity: entity(1, 'Avery Example'), why: { summary: 'Ranks #1 with 84.03: strongest in Review strength (18.5/20).' }, qualification: { value: 'car-accidents', status: 'verified' } },
    { position: 2, score: 77.84, movement: 1, isNew: false, entity: entity(2, 'Casey Placeholder', 'pending'), why: { summary: 'Ranks #2 with 77.84.' }, qualification: { value: 'car-accidents', status: 'unverified' } },
    { position: 3, score: 73.05, movement: null, isNew: true, entity: entity(3, 'Blake Sample') },
  ],
  context: { label: 'Car Accidents', eligibility: { qualified: 5, verified: 4, parentCount: 8 } },
  eligibility: { checks: [{ key: 'verified', value: 2 }] },
  sources: [{ name: 'State Bar Registry', tierLabel: 'Official / regulatory', facts: 56, entities: 8 }],
};

const qa: QaContext = { keyword: '', existingText: '', calculatedAt: null, isDemo: false, refsRequired: true, minWords: 0 };

describe('AI interpretation layer (Etap J)', () => {
  it('states the task and the shared rules in every prompt', () => {
    const system = interpretationSystem('explain', ['Explain the ranking.']);
    expect(system.startsWith('Task (explain):')).toBe(true);
    for (const rule of INTERPRETATION_RULES) expect(system).toContain(rule);
    expect(system).toMatch(/Never compute new numbers/);
    expect(system).toMatch(/Never decide, change, predict or judge a position/);
    expect(system).toMatch(/do not research, recall from memory/);
    expect(promptVersion('ranking-content/2')).toBe('interp/1+ranking-content/2');
  });

  it('builds ranking facts from what the backend computed', () => {
    const facts = buildRankingFacts(ranking, market);
    const by = (label: string) => facts.find((f) => f.label === label);
    expect(by('Ranked entries with verified profiles')).toMatchObject({ value: '2 of 3', status: 'computed', origin: 'page eligibility' });
    expect(by('Who is included')?.value).toContain('5 of 8 in the broader ranking, 4 confirmed by a verified fact');
    expect(by('Average client rating')).toMatchObject({ value: '4.7 out of 5 across 120 lawyers with a sourced rating', status: 'computed', origin: 'market mkt-1.0' });
    expect(by('Most common practice area')).toBeUndefined(); // the ranking is already one practice area
    expect(by('Sources behind the scores')?.value).toContain('State Bar Registry (Official / regulatory)');
    expect(by('Why #1 (Avery Example)')).toMatchObject({ status: 'computed', origin: 'ranking explainer' });
    expect(by('Position 2')?.value).toContain('car-accidents on record (sourced)');
  });

  it('uses market-wide figures on hubs instead of counting the profiles shown', () => {
    const hub: HubData = { kind: 'city', id: 7, slug: 'miami', name: 'Miami', stateName: 'Florida', lawyerCount: 127, lawFirmCount: 40 };
    const shown = [{ ...entity(1, 'Avery Example'), practiceAreas: [{ name: 'Personal Injury' }], ranking: { score: 84 }, isDemo: false }];
    const withMarket = buildHubFacts(hub, shown, [], market).map((f) => f.label);
    expect(withMarket).toContain('Lawyers with verified professional data');
    expect(withMarket).toContain('Most common practice area');
    expect(withMarket).not.toContain('Verified among the highest-scoring profiles shown');
    expect(buildHubFacts(hub, shown, [], null).map((f) => f.label)).toContain('Verified among the highest-scoring profiles shown');
  });

  it('carries each profile statement\'s evidence status', () => {
    const profile: ProfileData = {
      id: 1,
      type: 'lawyer',
      name: 'Avery Example',
      location: { city: 'Miami', state: 'Florida', stateCode: 'FL' },
      practiceAreas: [{ name: 'Personal Injury' }],
      rating: 4.9,
      reviewCount: 387,
      ranking: { score: 84.03, scoreVersion: 'v1.1' },
      verification: { status: 'verified' },
      professional: { yearsExperience: 22, barState: 'FL', barStatus: 'active', languages: ['English', 'Spanish'] },
      rankings: [],
      isDemo: false,
      aiSummary: {
        facts: [
          { key: 'rating', label: 'Client rating', value: '4.9/5', status: 'sourced', source: 'Review Platform' },
          { key: 'bar_status', label: 'Bar status', value: 'Active (FL)', status: 'verified', source: 'State Bar' },
        ],
      },
    };
    const facts = buildProfileFacts(profile);
    expect(facts.find((f) => f.label === 'Client rating')).toMatchObject({ status: 'sourced', origin: 'Review Platform' });
    expect(facts.find((f) => f.label === 'Bar status')).toMatchObject({ status: 'verified', origin: 'State Bar' });
    expect(facts.find((f) => f.label === 'LexRank score')).toMatchObject({ status: 'computed' });
  });

  describe('QA guards', () => {
    const facts: Fact[] = [
      { id: 'F1', label: 'Client rating', value: '4.9 from 387 reviews', kind: 'context', status: 'sourced' },
      { id: 'F2', label: 'Bar status', value: 'active (FL)', kind: 'context', status: 'verified' },
      { id: 'F3', label: 'Average client rating', value: '4.7 out of 5 across 120 lawyers', kind: 'context', status: 'computed' },
    ];
    const codes = (units: Unit[]) => checkContent(units, facts, qa).map((i) => i.code);

    it('rejects "verified" resting on a sourced fact', () => {
      expect(codes([{ where: 'summary', text: 'Avery has a verified 4.9 rating from 387 reviews.', factRefs: ['F1'] }])).toContain('overstated_verification');
      expect(codes([{ where: 'summary', text: 'Avery has a verified active bar status.', factRefs: ['F2'] }])).not.toContain('overstated_verification');
      expect(codes([{ where: 'summary', text: 'The 4.9 rating is not yet verified.', factRefs: ['F1'] }])).not.toContain('overstated_verification');
    });

    it('rejects ranking decisions and verdicts', () => {
      for (const text of ['Avery should be ranked higher.', 'Avery is a better lawyer.', 'Avery rates better than Casey.', 'We recommend Avery.', 'You should hire Avery.']) {
        expect(codes([{ where: 'summary', text, factRefs: ['F1'] }])).toContain('ranking_decision');
      }
      expect(codes([{ where: 'summary', text: 'Avery holds a 4.9 rating from 387 reviews.', factRefs: ['F1'] }])).toEqual([]);
    });

    it('rejects numbers the model computed itself', () => {
      // 4.8 would be an average the model worked out; only 4.7 is the backend's figure.
      expect(codes([{ where: 'summary', text: 'The average rating is 4.8.', factRefs: ['F3'] }])).toContain('unsupported_number');
      expect(codes([{ where: 'summary', text: 'The average rating is 4.7 out of 5 across 120 lawyers.', factRefs: ['F3'] }])).toEqual([]);
    });
  });
});
