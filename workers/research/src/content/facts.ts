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
