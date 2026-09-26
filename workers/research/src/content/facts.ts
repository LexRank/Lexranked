/**
 * The only material a content model may use: a numbered, deterministic list
 * of facts built from the public ranking data (published entities, approved
 * evidence, engine snapshots). Same ranking data → same facts, same order.
 */

export interface RankingEntity {
  id: number;
  name: string;
  type: 'lawyer' | 'law_firm';
  location: { city: string | null; state: string | null; stateCode: string | null } | null;
  rating: number | null;
  reviewCount: number | null;
  verification: { status: string } | null;
  firm?: { name: string } | null;
  practiceAreas?: { name: string }[];
}

export interface RankingData {
  id: number;
  title: string;
  entityType: 'lawyer' | 'law_firm';
  location: { city: string | null; state: string | null; stateCode: string | null } | null;
  practiceArea: { slug: string; name: string } | null;
  scoreVersion: string | null;
  entryCount: number;
  minEntities: number;
  isThin: boolean;
  isDemo: boolean;
  calculatedAt: string | null;
  methodologyUrl: string;
  summary: string | null;
  body: string;
  faq: { question: string; answer: string }[];
  entries: { position: number; score: number; movement: number | null; isNew: boolean; entity: RankingEntity }[];
}

export type FactKind = 'context' | 'entry';

export interface Fact {
  id: string;
  label: string;
  value: string;
  kind: FactKind;
  /** For entry facts: the entity's position and name, used by QA. */
  position?: number;
  name?: string;
}

export const MAX_ENTRIES_IN_FACTS = 10;

const VERIFICATION: Record<string, string> = {
  verified: 'verified by LexRanked',
  pending: 'verification pending',
  expired: 'verification expired',
  failed: 'verification failed',
  unverified: 'not yet verified',
};

export function place(r: RankingData): string {
  const loc = r.location;
  if (!loc) return 'the United States';
  if (loc.city && loc.state) return `${loc.city}, ${loc.state}`;
  return loc.state ?? loc.city ?? 'the United States';
}

export function buildRankingFacts(r: RankingData): Fact[] {
  const facts: Fact[] = [];
  const add = (label: string, value: string, extra: Partial<Fact> = {}): void => {
    facts.push({ id: `F${facts.length + 1}`, label, value, kind: 'context', ...extra });
  };
  const noun = r.entityType === 'law_firm' ? 'law firms' : 'lawyers';
  add('Ranking title', r.title);
  add('Location', place(r));
  if (r.practiceArea) add('Practice area', r.practiceArea.name);
  add('What is ranked', noun);
  add('Number of ranked ' + noun, String(r.entries.length));
  if (r.scoreVersion) add('Scoring methodology version', `LexRank methodology ${r.scoreVersion} (published at ${r.methodologyUrl})`);
  add('How positions are decided', 'Positions follow the LexRank score, a deterministic 0–100 score from verified data, sources, experience and reviews; payment never affects positions.');
  if (r.calculatedAt) add('Last calculated', r.calculatedAt.slice(0, 10));
  const verified = r.entries.filter((e) => e.entity.verification?.status === 'verified').length;
  add('Ranked entries with verified profiles', `${verified} of ${r.entries.length}`);

  for (const e of r.entries.slice(0, MAX_ENTRIES_IN_FACTS)) {
    const x = e.entity;
    const parts = [`#${e.position} ${x.name}`, `LexRank score ${e.score.toFixed(2)}`];
    if (x.location?.city) parts.push(`based in ${x.location.city}${x.location.stateCode ? ', ' + x.location.stateCode : ''}`);
    if (x.firm?.name) parts.push(`at ${x.firm.name}`);
    if (x.rating !== null && x.reviewCount !== null && x.reviewCount > 0) parts.push(`rated ${x.rating.toFixed(1)} from ${x.reviewCount} reviews`);
    parts.push(VERIFICATION[x.verification?.status ?? 'unverified'] ?? 'not yet verified');
    if (e.isNew) parts.push('new in this ranking');
    else if (e.movement !== null && e.movement !== 0) parts.push(e.movement > 0 ? `up ${e.movement} since the previous calculation` : `down ${-e.movement} since the previous calculation`);
    facts.push({ id: `F${facts.length + 1}`, label: `Position ${e.position}`, value: parts.join('; '), kind: 'entry', position: e.position, name: x.name });
  }
  return facts;
}

// ─── Phase 7: hubs, profiles, articles ────────────────────────────────────

export interface EntitySummary {
  id: number;
  name: string;
  type: 'lawyer' | 'law_firm';
  location: { city: string | null; state: string | null; stateCode: string | null } | null;
  practiceAreas: { name: string }[];
  rating: number | null;
  reviewCount: number | null;
  ranking: { score: number | null };
  verification: { status: string } | null;
  isDemo: boolean;
}

export interface HubData {
  kind: 'state' | 'city' | 'practice_area';
  id: number;
  slug: string;
  name: string;
  /** For cities: the state's name. */
  stateName?: string | null;
  lawyerCount: number;
  lawFirmCount: number;
  content?: { summary: string | null; body: string; faq: { question: string; answer: string }[] } | null;
}

export function hubPlace(h: HubData): string {
  if (h.kind === 'practice_area') return h.name;
  return h.kind === 'city' && h.stateName ? `${h.name}, ${h.stateName}` : h.name;
}

/** Facts for a state / city / practice-area page (top profiles by LexRank score). */
export function buildHubFacts(h: HubData, lawyers: EntitySummary[], rankings: { title: string; entryCount: number }[]): Fact[] {
  const facts: Fact[] = [];
  const add = (label: string, value: string, extra: Partial<Fact> = {}): void => {
    facts.push({ id: `F${facts.length + 1}`, label, value, kind: 'context', ...extra });
  };
  add(h.kind === 'practice_area' ? 'Practice area' : 'Location', hubPlace(h));
  add('Published lawyer profiles', String(h.lawyerCount));
  add('Published law firm profiles', String(h.lawFirmCount));
  add('How profiles are ordered', 'Profiles are ordered by the LexRank score, a deterministic 0–100 score from verified data, sources, experience and reviews; payment never affects positions.');
  const verified = lawyers.filter((l) => l.verification?.status === 'verified').length;
  if (lawyers.length > 0) add('Verified among the highest-scoring profiles shown', `${verified} of ${lawyers.length}`);
  const areas = [...new Set(lawyers.flatMap((l) => l.practiceAreas.map((p) => p.name)))].sort();
  if (h.kind !== 'practice_area' && areas.length > 0) add('Practice areas covered', areas.join(', '));
  for (const r of rankings.slice(0, 5)) add('Ranking', `${r.title} (${r.entryCount} ranked)`);
  lawyers.slice(0, MAX_ENTRIES_IN_FACTS).forEach((l, i) => {
    const parts = [`${l.name}`];
    if (l.ranking.score !== null) parts.push(`LexRank score ${l.ranking.score.toFixed(2)}`);
    if (l.location?.city) parts.push(`based in ${l.location.city}${l.location.stateCode ? ', ' + l.location.stateCode : ''}`);
    if (l.rating !== null && l.reviewCount) parts.push(`rated ${l.rating.toFixed(1)} from ${l.reviewCount} reviews`);
    parts.push(VERIFICATION[l.verification?.status ?? 'unverified'] ?? 'not yet verified');
    facts.push({ id: `F${facts.length + 1}`, label: `Highest-scoring profile ${i + 1}`, value: parts.join('; '), kind: 'entry', position: i + 1, name: l.name });
  });
  return facts;
}

export interface ProfileData {
  id: number;
  type: 'lawyer' | 'law_firm';
  name: string;
  title?: string | null;
  firm?: { name: string } | null;
  location: { city: string | null; state: string | null; stateCode: string | null } | null;
  practiceAreas: { name: string }[];
  rating: number | null;
  reviewCount: number | null;
  ranking: { score: number | null; scoreVersion: string | null };
  verification: { status: string } | null;
  professional?: { yearsExperience: number | null; barState: string | null; barStatus: string | null; languages: string[] };
  lawyers?: { name: string }[];
  rankings: { title: string; position: number }[];
  summary?: string | null;
  isDemo: boolean;
}

/** Facts for a profile summary: published fields and engine output only. */
export function buildProfileFacts(p: ProfileData): Fact[] {
  const facts: Fact[] = [];
  const add = (label: string, value: string, extra: Partial<Fact> = {}): void => {
    facts.push({ id: `F${facts.length + 1}`, label, value, kind: 'context', ...extra });
  };
  add('Name', p.name);
  add('Type', p.type === 'law_firm' ? 'law firm' : 'lawyer');
  if (p.title) add('Professional title', p.title);
  if (p.firm?.name) add('Law firm', p.firm.name);
  const loc = p.location;
  if (loc?.city || loc?.state) add('Location', [loc?.city, loc?.state].filter(Boolean).join(', '));
  if (p.practiceAreas.length > 0) add('Practice areas', p.practiceAreas.map((a) => a.name).join(', '));
  if (p.professional?.yearsExperience) add('Years of experience', String(p.professional.yearsExperience));
  if (p.professional?.barState && p.professional.barStatus) add('Bar status', `${p.professional.barStatus} (${p.professional.barState})`);
  if (p.professional?.languages.length) add('Languages', p.professional.languages.join(', '));
  if (p.lawyers?.length) add('Lawyers profiled at the firm', String(p.lawyers.length));
  if (p.rating !== null && p.reviewCount) add('Client rating', `${p.rating.toFixed(1)} from ${p.reviewCount} reviews`);
  if (p.ranking.score !== null) add('LexRank score', `${p.ranking.score.toFixed(2)}${p.ranking.scoreVersion ? ` (methodology ${p.ranking.scoreVersion})` : ''}`);
  add('Verification', VERIFICATION[p.verification?.status ?? 'unverified'] ?? 'not yet verified');
  for (const r of p.rankings.slice(0, 5)) facts.push({ id: `F${facts.length + 1}`, label: 'Ranking position', value: `#${r.position} in ${r.title}`, kind: 'entry', position: r.position, name: p.name });
  return facts;
}

export interface MethodologyData {
  active: string;
  versions: { id: string; weights: { label: string; weight: number }[] }[];
}

/** Facts about the published methodology (for articles). */
export function buildMethodologyFacts(m: MethodologyData, startAt = 1): Fact[] {
  const v = m.versions.find((x) => x.id === m.active);
  const facts: Fact[] = [];
  const add = (label: string, value: string): void => {
    facts.push({ id: `F${startAt + facts.length}`, label, value, kind: 'context' });
  };
  add('Methodology version', m.active);
  add('How positions are decided', 'Positions follow the LexRank score, a deterministic 0–100 score; the same inputs always give the same score; payment never affects positions; missing data scores zero and is never estimated.');
  if (v) add('Score components and weights', v.weights.map((w) => `${w.label} ${w.weight}%`).join(', '));
  add('Verification', 'A profile is shown as verified only when required checks such as licence and bar status are confirmed by an authoritative source and have not expired.');
  return facts;
}
