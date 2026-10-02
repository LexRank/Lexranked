import { describe, expect, it } from 'vitest';
import { csvRecords, parseCsv } from '../src/csv.js';
import { extractFacts, jsonLdNodes } from '../src/extract.js';
import { domainOf, normalizeName } from '../src/normalize.js';
import { parseSeedCsv, loadDataset, ProviderError } from '../src/providers/csvSeed.js';
import { Robots } from '../src/robots.js';

describe('parseCsv', () => {
  it('handles quotes, escaped quotes, CRLF and a BOM', () => {
    expect(parseCsv('﻿a,b\r\n"x, y","say ""hi"""\n\n')).toEqual([
      ['a', 'b'],
      ['x, y', 'say "hi"'],
    ]);
    expect(parseCsv('a\n"multi\nline"')).toEqual([['a'], ['multi\nline']]);
  });

  it('rejects unterminated quotes', () => {
    expect(() => parseCsv('a,"b')).toThrow(/Unterminated/);
  });

  it('keys records by trimmed lower-case headers', () => {
    expect(csvRecords(' Name ,City\nAna,Miami')).toEqual([{ name: 'Ana', city: 'Miami' }]);
  });
});

describe('normalizeName (parity with PHP CandidateNormalizer)', () => {
  it.each([
    ['John A. Smith, Esq.', 'lawyer', 'john smith'],
    ['JOHN SMITH', 'lawyer', 'john smith'],
    ['José Martínez', 'lawyer', 'jose martinez'],
    ['The Smith & Jones Law Group, P.A.', 'law_firm', 'smith jones'],
    ['Jane A. Doe, Esq.', 'lawyer', 'jane doe'],
  ] as const)('%s → %s', (input, type, expected) => {
    expect(normalizeName(input, type)).toBe(expected);
  });

  it('extracts domains', () => {
    expect(domainOf('https://www.Doe-Law.example/about')).toBe('doe-law.example');
    expect(domainOf('not a url')).toBeNull();
  });
});

describe('Robots', () => {
  const txt = `User-agent: *\nDisallow: /private\nAllow: /private/public\n\nUser-agent: lexrankedbot\nDisallow: /no-bots$\nDisallow: /*.pdf`;

  it('uses the most specific group and longest match', () => {
    const generic = Robots.parse(txt, 'otherbot');
    expect(generic.isAllowed('/private/x')).toBe(false);
    expect(generic.isAllowed('/private/public/x')).toBe(true);
    const ours = Robots.parse(txt, 'lexrankedbot');
    expect(ours.isAllowed('/private/x')).toBe(true);
    expect(ours.isAllowed('/no-bots')).toBe(false);
    expect(ours.isAllowed('/no-bots/ok')).toBe(true);
    expect(ours.isAllowed('/files/a.pdf')).toBe(false);
  });

  it('treats an empty Disallow as allow-all', () => {
    expect(Robots.parse('User-agent: *\nDisallow:', 'x').isAllowed('/anything')).toBe(true);
  });
});

describe('extractFacts', () => {
  const firmPage = `<html><head>
    <script type="application/ld+json">{"@context":"https://schema.org","@graph":[
      {"@type":"LegalService","name":"Sample & Fixture, P.A.","telephone":"+1 305 555 0199","url":"https://sample.example/","email":"mailto:office@sample.example",
       "address":{"@type":"PostalAddress","streetAddress":"1 Main St","addressLocality":"Miami","addressRegion":"FL","postalCode":"33101"}},
      {"@type":"Person","name":"Jordan Sample","telephone":"+1 305 555 0101"}
    ]}</script>
    <script type="application/ld+json">{ not json }</script>
  </head></html>`;

  it('reads facts only from the node describing the entity', () => {
    const firm = extractFacts(firmPage, 'https://sample.example/contact', { type: 'law_firm', name: 'Sample and Fixture PA' });
    expect(firm.matched).toBe(true);
    expect(firm.facts).toEqual([
      { field_name: 'phone', value: '+1 305 555 0199' },
      { field_name: 'website', value: 'https://sample.example/' },
      { field_name: 'email', value: 'office@sample.example' },
      { field_name: 'city', value: 'Miami' },
      { field_name: 'state', value: 'FL' },
      { field_name: 'zip_code', value: '33101' },
      { field_name: 'address', value: '1 Main St' },
    ]);

    const lawyer = extractFacts(firmPage, 'https://sample.example/contact', { type: 'lawyer', name: 'Jordan Q. Sample, Esq.' });
    expect(lawyer.facts).toEqual([{ field_name: 'phone', value: '+1 305 555 0101' }]);
  });

  it('never attributes another entity’s data', () => {
    expect(extractFacts(firmPage, 'https://sample.example/', { type: 'lawyer', name: 'Casey Fixture' })).toEqual({ matched: false, facts: [] });
    expect(jsonLdNodes('<p>no data</p>')).toEqual([]);
  });

  it('ignores a url on another domain and malformed ZIP codes', () => {
    const page = '<script type="application/ld+json">{"@type":"Attorney","name":"Casey Fixture","url":"https://elsewhere.example/","address":{"postalCode":"ABCDE"}}</script>';
    expect(extractFacts(page, 'https://casey.example/', { type: 'lawyer', name: 'Casey Fixture' }).facts).toEqual([]);
  });
});

describe('seed CSV provider', () => {
  const header = 'entity_type,name,source_url,source_type,retrieved_at,city,state\n';

  it('validates rows without dropping their position', () => {
    const rows = parseSeedCsv(header + 'lawyer,Ana Test,https://s.test/1,bar_association,2026-09-01,Miami,FL\njudge,X Y,https://s.test/2,bar_association,2026-09-01,,\nlawyer,B C,https://s.test/3,bar_association,not-a-date,,');
    expect(rows.map((r) => r.ok)).toEqual([true, false, false]);
    expect(rows[0]).toMatchObject({ ok: true, row: { city: 'Miami', confidence: 0.9, retrieved_at: '2026-09-01T00:00:00.000Z' } });
  });

  it('requires provenance columns', () => {
    expect(() => parseSeedCsv('entity_type,name\nlawyer,Ana Test')).toThrow(ProviderError);
  });

  it('refuses dataset names that could escape the data directory', async () => {
    await expect(loadDataset('fixtures/datasets', '../secrets')).rejects.toThrow(ProviderError);
    await expect(loadDataset('fixtures/datasets', 'missing-set')).rejects.toThrow(/not found/);
    const rows = await loadDataset('fixtures/datasets', 'fictional-demo');
    expect(rows).toHaveLength(6);
  });
});
