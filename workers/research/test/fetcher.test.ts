import { describe, expect, it } from 'vitest';
import { FetchRefused, isPrivateAddress, SafeFetcher } from '../src/fetcher.js';

type Route = (url: URL) => Response;

function fetcher(routes: Record<string, Route>, extra: Partial<ConstructorParameters<typeof SafeFetcher>[0]> = {}) {
  const calls: string[] = [];
  const sleeps: number[] = [];
  let clock = 1_000;
  const f = new SafeFetcher({
    userAgent: 'LexRankedBot/0.5',
    timeoutMs: 1000,
    maxBytes: 1000,
    perHostIntervalMs: 2000,
    allowPrivateNetwork: false,
    resolve: async (host) => (host.endsWith('.internal') ? ['10.0.0.5'] : ['93.184.216.34']),
    now: () => clock,
    sleep: async (ms) => {
      sleeps.push(ms);
      clock += ms;
    },
    fetchImpl: (async (input: string | URL | Request) => {
      const url = new URL(String(input));
      calls.push(url.href);
      const route = routes[url.pathname];
      return route ? route(url) : new Response('nope', { status: 404 });
    }) as typeof fetch,
    ...extra,
  });
  return { f, calls, sleeps };
}

const html = (body: string, headers: Record<string, string> = {}) => new Response(body, { status: 200, headers: { 'content-type': 'text/html; charset=utf-8', ...headers } });

describe('SafeFetcher', () => {
  it('classifies private addresses', () => {
    for (const ip of ['10.1.2.3', '127.0.0.1', '192.168.1.1', '172.20.0.1', '169.254.169.254', '::1', 'fd00::1', '::ffff:10.0.0.1']) {
      expect(isPrivateAddress(ip), ip).toBe(true);
    }
    for (const ip of ['93.184.216.34', '8.8.8.8', '2606:4700::1111']) {
      expect(isPrivateAddress(ip), ip).toBe(false);
    }
  });

  it('refuses hosts resolving to private networks (SSRF)', async () => {
    const { f, calls } = fetcher({ '/': () => html('x') });
    await expect(f.fetchHtml('http://metadata.internal/')).rejects.toMatchObject({ reason: 'private_network' });
    await expect(f.fetchHtml('http://127.0.0.1/')).rejects.toMatchObject({ reason: 'private_network' });
    await expect(f.fetchHtml('ftp://example.com/')).rejects.toMatchObject({ reason: 'scheme' });
    expect(calls).toEqual([]);
  });

  it('honours robots.txt and rate-limits per host', async () => {
    const { f, sleeps } = fetcher({
      '/robots.txt': () => new Response('User-agent: *\nDisallow: /secret', { status: 200 }),
      '/page': () => html('<p>ok</p>'),
    });
    await expect(f.fetchHtml('https://site.example/secret/x')).rejects.toMatchObject({ reason: 'robots' });
    const page = await f.fetchHtml('https://site.example/page');
    expect(page.html).toBe('<p>ok</p>');
    expect(sleeps).toEqual([2000]); // robots.txt and the page are two hits on one host.
  });

  it('treats an unreachable robots.txt as disallowed', async () => {
    const { f } = fetcher({ '/robots.txt': () => new Response('down', { status: 503 }), '/': () => html('x') });
    await expect(f.fetchHtml('https://down.example/')).rejects.toBeInstanceOf(FetchRefused);
  });

  it('re-checks every redirect hop', async () => {
    const { f } = fetcher({
      '/start': () => new Response(null, { status: 301, headers: { location: 'http://evil.internal/admin' } }),
    });
    await expect(f.fetchHtml('https://site.example/start')).rejects.toMatchObject({ reason: 'private_network' });
  });

  it('caps size and requires HTML', async () => {
    const { f } = fetcher({ '/big': () => html('x'.repeat(5000)), '/pdf': () => new Response('%PDF', { headers: { 'content-type': 'application/pdf' } }) });
    await expect(f.fetchHtml('https://site.example/big')).rejects.toMatchObject({ reason: 'too_large' });
    await expect(f.fetchHtml('https://site.example/pdf')).rejects.toMatchObject({ reason: 'content_type' });
  });
});
