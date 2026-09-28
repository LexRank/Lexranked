/**
 * Deterministic quality checks for generated ranking content. Every check is
 * explainable and reproducible; an optional AI pass may only ADD issues.
 *
 * error   → draft is "needs_review" (an editor must acknowledge each)
 * warning → shown to the editor, draft can still be "ready_for_review"
 */

import type { Fact, RankingData } from './facts.js';

/** What QA needs to know about the page besides the text and facts. */
export interface QaContext {
  /** Target phrase for the stuffing check (e.g. "personal injury miami"). */
  keyword: string;
  /** Text already on the page (duplicate check). */
  existingText: string;
  /** When the underlying data was calculated (freshness), if known. */
  calculatedAt: string | null;
  isDemo: boolean;
  /** Articles may contain general guidance paragraphs without fact references. */
  refsRequired: boolean;
  /** Minimum words before "too_short" (0 disables). */
  minWords: number;
}

export function rankingQaContext(r: RankingData): QaContext {
  return {
    keyword: [r.practiceArea?.name, r.location?.city].filter(Boolean).join(' '),
    existingText: [r.summary ?? '', r.body.replace(/<[^>]+>/g, ' '), ...r.faq.map((f) => `${f.question} ${f.answer}`)].join(' '),
    calculatedAt: r.calculatedAt,
    isDemo: r.isDemo,
    refsRequired: true,
    minWords: 120,
  };
}

export interface Unit {
  /** Where the text is, e.g. "summary", "sections[0].paragraphs[1]", "faq[2].answer". */
  where: string;
  text: string;
  factRefs: string[];
}

export interface Issue {
  code: string;
  severity: 'error' | 'warning';
  message: string;
  excerpt: string;
}

const ERROR_PHRASES = [
  /\bguarantee[ds]?\b/i,
  /\bpromise[sd]?\b/i,
  /\bwill win\b/i,
  /\b100\s?%/i,
  /\brisk[- ]free\b/i,
  /\bno win,? no fee\b/i,
  /\bcall (us|now|today)\b/i,
  /\bsponsored\b/i,
];
/**
 * Etap J: the model interprets; it never decides or judges a position. These
 * phrases assert a ranking decision or a verdict, which only the LexRank
 * engine (positions) or the reader (choices) can make.
 */
const DECISION_PHRASES = [
  /\b(?:should|deserves? to|ought to) (?:be )?(?:ranked|placed|listed|rank) (?:higher|lower|first|above|below|#?\d+)\b/i,
  /\b(?:is|are) (?:a )?(?:better|worse) (?:lawyer|attorney|firm|choice|option)s?\b/i,
  /\b(?:better|worse) than\b/i,
  /\bwe (?:recommend|suggest)\b/i,
  /\byou should (?:hire|choose|pick|retain|call)\b/i,
];

const WARNING_PHRASES = [/\bthe best\b/i, /\btop[- ]rated\b/i, /\baward[- ]winning\b/i, /\bpremier\b/i, /\bleading\b/i, /\bcheapest\b/i, /\bmost trusted\b/i];

const NUMBER = /(?<![\w.])\d+(?:[.,]\d+)*(?![\w])/g;

function sentences(text: string): string[] {
  return text.split(/(?<=[.!?])\s+/).map((s) => s.trim()).filter(Boolean);
}

function norm(text: string): string {
  return text.toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
}

function shingles(text: string, n = 5): Set<string> {
  const words = norm(text).split(' ');
  const out = new Set<string>();
  for (let i = 0; i + n <= words.length; i++) out.add(words.slice(i, i + n).join(' '));
  return out;
}

export function similarity(a: string, b: string): number {
  const x = shingles(a);
  const y = shingles(b);
  if (x.size === 0 || y.size === 0) return 0;
  let inter = 0;
  for (const s of x) if (y.has(s)) inter++;
  return inter / Math.min(x.size, y.size);
}

function canonicalNumber(n: string): string {
  return String(Number(n.replace(/,/g, '')));
}

function numbersIn(text: string): string[] {
  return (text.match(NUMBER) ?? []).map(canonicalNumber);
}

/** Every digit group in a fact ("v1.0" → 1, "2026-09-01" → 2026, 9, 1). */
function factNumbers(text: string): string[] {
  return (text.match(/\d+(?:[.,]\d+)*/g) ?? []).flatMap((n) => [canonicalNumber(n), ...n.split(/[.,]/).map(canonicalNumber)]);
}

export function checkContent(units: Unit[], facts: Fact[], page: QaContext | RankingData, now: Date = new Date()): Issue[] {
  const ctx: QaContext = 'entries' in page ? rankingQaContext(page) : page;
  const issues: Issue[] = [];
  const byId = new Map(facts.map((f) => [f.id, f]));
  const entries = facts.filter((f) => f.kind === 'entry');
  const add = (code: string, severity: Issue['severity'], message: string, excerpt: string): void => {
    issues.push({ code, severity, message, excerpt: excerpt.slice(0, 280) });
  };

  for (const u of units) {
    const refs = u.factRefs.filter((id) => byId.has(id));
    if (u.text.trim() === '') continue;
    if (refs.length === 0 && !u.where.endsWith('.question') && !u.where.endsWith('.heading') && u.where !== 'title') {
      if (ctx.refsRequired) {
        add('missing_fact_refs', 'error', `${u.where} does not cite any supplied fact.`, u.text);
      } else if (entries.some((f) => f.name && u.text.toLowerCase().includes(f.name.toLowerCase()))) {
        add('uncited_entity', 'error', `${u.where} names a lawyer or firm without citing the fact it relies on.`, u.text);
      }
    }
    if (u.factRefs.length !== refs.length) {
      add('unknown_fact_ref', 'error', `${u.where} cites facts that were not supplied.`, u.factRefs.join(', '));
    }
    // Numbers must come from the cited facts (or, for questions/headings, from any fact).
    const headingLike = u.where.endsWith('.question') || u.where.endsWith('.heading') || u.where === 'title';
    // Uncited guidance (articles) may not contain numbers at all.
    const poolFacts = refs.length > 0 && !headingLike ? refs.map((id) => byId.get(id) as Fact) : headingLike || ctx.refsRequired ? facts : [];
    const pool = poolFacts.map((f) => `${f.label} ${f.value}`);
    const allowed = new Set(pool.flatMap(factNumbers));
    for (const n of numbersIn(u.text)) {
      if (!allowed.has(n)) add('unsupported_number', 'error', `${u.where}: "${n}" is not in the cited facts.`, u.text);
    }
    // Ranking positions next to names must match the ranking.
    for (const s of sentences(u.text)) {
      const positions = [...s.matchAll(/(?:#|No\.\s?|number\s|ranked\s|ranks\s)(\d+)\b/gi)].map((m) => Number(m[1]));
      if (positions.length === 0) continue;
      const named = entries.filter((f) => f.name && s.toLowerCase().includes(f.name.toLowerCase()));
      if (named.length === 1 && !positions.includes(named[0]?.position as number)) {
        add('wrong_position', 'error', `${u.where}: ${named[0]?.name} is #${named[0]?.position}, not #${positions.join('/#')}.`, s);
      }
    }
    for (const re of ERROR_PHRASES) {
      const m = re.exec(u.text);
      if (m) add('forbidden_claim', 'error', `${u.where}: "${m[0]}" is a promise or promotional claim LexRanked does not make.`, u.text);
    }
    for (const re of DECISION_PHRASES) {
      const m = re.exec(u.text);
      if (m) add('ranking_decision', 'error', `${u.where}: "${m[0]}" decides or judges a position; only the LexRank engine sets positions and the reader chooses.`, u.text);
    }
    // "Verified" must rest on a verified fact (Etap J): sourced facts are not verified.
    if (/\bverified\b/i.test(u.text.replace(/\b(?:not yet|not|un)[\s-]?verified\b/gi, '')) && refs.length > 0) {
      const cited = refs.map((id) => byId.get(id) as Fact);
      const supports = cited.some((f) => f.status === 'verified' || /verif/i.test(`${f.label} ${f.value}`));
      if (!supports) add('overstated_verification', 'error', `${u.where} says "verified" but none of the cited facts is verified.`, u.text);
    }
    for (const re of WARNING_PHRASES) {
      const m = re.exec(u.text);
      if (m) add('promotional_language', 'warning', `${u.where}: "${m[0]}" is not supported by the methodology; prefer "highest-scoring".`, u.text);
    }
    if (/https?:\/\/|www\./i.test(u.text)) add('link_in_text', 'error', `${u.where}: links are added by the site, not written into content.`, u.text);
  }

  // Duplicates within the draft and against the current page text.
  const seen = new Map<string, string>();
  for (const u of units) {
    for (const s of sentences(u.text)) {
      const key = norm(s);
      if (key.split(' ').length < 6) continue;
      if (seen.has(key)) add('duplicate_sentence', 'warning', `Sentence repeated in ${seen.get(key)} and ${u.where}.`, s);
      else seen.set(key, u.where);
    }
  }
  const draftText = units.map((u) => u.text).join(' ');
  const existing = ctx.existingText;
  if (existing.trim() !== '' && similarity(draftText, existing) > 0.6) {
    add('duplicate_content', 'warning', 'The draft largely repeats the text already on the page.', '');
  }

  // Keyword stuffing: the target phrase should read naturally.
  const words = norm(draftText).split(' ').filter(Boolean);
  const keyword = norm(ctx.keyword);
  if (keyword !== '' && words.length > 0) {
    const hits = norm(draftText).split(keyword).length - 1;
    if (hits > 6 || hits / Math.max(1, words.length / 100) > 3) {
      add('keyword_stuffing', 'warning', `"${keyword}" appears ${hits} times in ${words.length} words.`, '');
    }
  }
  if (ctx.minWords > 0 && words.length < ctx.minWords) add('too_short', 'warning', `Only ${words.length} words; the page may not add enough context.`, '');

  // Freshness and data quality.
  if (ctx.calculatedAt && now.getTime() - Date.parse(ctx.calculatedAt) > 30 * 86_400_000) {
    add('outdated_data', 'warning', `Data was last calculated on ${ctx.calculatedAt.slice(0, 10)}; recalculate before publishing.`, '');
  }
  if (ctx.isDemo) add('demo_data', 'warning', 'This page uses demo data; never publish generated text for it on a live site.', '');
  return issues;
}
