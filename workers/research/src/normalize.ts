/**
 * Name normalization - mirrors LexRanked\Core\Research\CandidateNormalizer
 * (PHP) so the worker can tell whether structured data on a web page is
 * about the entity it is researching. Keep both in sync (parity-tested).
 */

const PERSON_NOISE = new Set(['esq', 'esquire', 'jd', 'attorney', 'atty', 'lawyer', 'mr', 'mrs', 'ms', 'dr', 'hon']);
const FIRM_NOISE = new Set(['llp', 'llc', 'pllc', 'pa', 'pc', 'inc', 'ltd', 'co', 'the', 'law', 'firm', 'group', 'office', 'offices', 'of', 'and', 'attorneys', 'at']);

export type EntityType = 'lawyer' | 'law_firm';

export function normalizeName(name: string, type: EntityType): string {
  let ascii = name.normalize('NFD').replace(/\p{Mn}+/gu, '').replace(/[^\x20-\x7E]/g, '');
  ascii = ascii.replaceAll('&', ' and ').replaceAll('.', '');
  ascii = ascii.toLowerCase().replace(/[^a-z0-9 ]+/g, ' ');
  const noise = type === 'law_firm' ? FIRM_NOISE : PERSON_NOISE;
  let words = ascii.split(' ').filter((w) => w !== '' && !noise.has(w));
  if (type === 'lawyer' && words.length > 2) {
    const first = words[0] as string;
    const last = words[words.length - 1] as string;
    words = [first, ...words.slice(1, -1).filter((w) => w.length > 1), last];
  }
  return words.join(' ');
}

export function domainOf(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const host = new URL(url).hostname.toLowerCase().replace(/^www\./, '');
    return host === '' ? null : host;
  } catch {
    return null;
  }
}
