/**
 * Fact extraction from schema.org JSON-LD embedded in a page.
 *
 * Only structured data the publisher states about the entity itself is used,
 * and only when its name matches the entity we are researching — so a firm's
 * page is never mistaken for facts about one of its lawyers. No free-text
 * scraping and no AI: every fact maps to one explicit JSON-LD property.
 */

import { domainOf, normalizeName, type EntityType } from './normalize.js';

export interface Fact {
  field_name: string;
  value: string;
}

type Node = Record<string, unknown>;

const TYPES: Record<EntityType, string[]> = {
  lawyer: ['Person', 'Attorney'],
  law_firm: ['LegalService', 'Attorney', 'LawFirm', 'Organization', 'LocalBusiness', 'ProfessionalService'],
};

export function jsonLdNodes(html: string): Node[] {
  const nodes: Node[] = [];
  const re = /<script\b[^>]*type\s*=\s*["']?application\/ld\+json["']?[^>]*>([\s\S]*?)<\/script>/gi;
  for (const match of html.matchAll(re)) {
    let parsed: unknown;
    try {
      parsed = JSON.parse((match[1] ?? '').trim());
    } catch {
      continue; // Invalid JSON-LD is ignored, never guessed at.
    }
    collect(parsed, nodes, 0);
  }
  return nodes;
}

function collect(value: unknown, out: Node[], depth: number): void {
  if (depth > 5 || value === null || typeof value !== 'object') return;
  if (Array.isArray(value)) {
    for (const v of value) collect(v, out, depth + 1);
    return;
  }
  const node = value as Node;
  if ('@type' in node) out.push(node);
  if (Array.isArray(node['@graph'])) collect(node['@graph'], out, depth + 1);
}

function types(node: Node): string[] {
  const t = node['@type'];
  return (Array.isArray(t) ? t : [t]).filter((x): x is string => typeof x === 'string');
}

function text(value: unknown, max = 200): string | null {
  if (typeof value !== 'string') return null;
  const clean = value.replace(/\s+/g, ' ').trim();
  return clean === '' || clean.length > max ? null : clean;
}

/**
 * Facts about `entity` stated in the page's JSON-LD. Returns matched=false
 * when no node describes an entity of that type and name.
 */
export function extractFacts(html: string, pageUrl: string, entity: { type: EntityType; name: string }): { matched: boolean; facts: Fact[] } {
  const wanted = normalizeName(entity.name, entity.type);
  const node = jsonLdNodes(html).find(
    (n) => types(n).some((t) => TYPES[entity.type].includes(t)) && typeof n.name === 'string' && normalizeName(n.name, entity.type) === wanted,
  );
  if (!node || wanted === '') return { matched: false, facts: [] };

  const facts: Fact[] = [];
  const add = (field_name: string, value: string | null): void => {
    if (value !== null && !facts.some((f) => f.field_name === field_name)) facts.push({ field_name, value });
  };

  add('phone', text(node.telephone, 40));

  const pageDomain = domainOf(pageUrl);
  const url = text(node.url, 2048);
  if (url && /^https?:\/\//i.test(url) && domainOf(url) === pageDomain) add('website', url);

  if (entity.type === 'law_firm') {
    const email = text(node.email, 200)?.replace(/^mailto:/i, '') ?? null;
    add('email', email && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) ? email : null);
  }

  const address = Array.isArray(node.address) ? node.address[0] : node.address;
  if (address && typeof address === 'object') {
    const a = address as Node;
    add('city', text(a.addressLocality, 100));
    add('state', text(a.addressRegion, 100));
    const zip = text(a.postalCode, 10);
    add('zip_code', zip && /^\d{5}(-\d{4})?$/.test(zip) ? zip : null);
    if (entity.type === 'law_firm') add('address', text(a.streetAddress, 200));
  }
  return { matched: true, facts };
}
