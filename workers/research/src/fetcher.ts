/**
 * Polite, bounded HTTP fetcher for public web pages.
 *
 * - http(s) only; hosts resolving to private/loopback/link-local addresses
 *   are refused (SSRF guard) unless explicitly allowed for local testing;
 * - robots.txt is honoured for every hop (unreachable robots.txt = disallow);
 * - one request per host per interval; timeout; body size cap; HTML only;
 * - at most 3 redirects, each re-checked.
 */

import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';
import { Robots } from './robots.js';

export interface FetchOptions {
  userAgent: string;
  timeoutMs: number;
  maxBytes: number;
  perHostIntervalMs: number;
  allowPrivateNetwork: boolean;
  fetchImpl?: typeof fetch;
  resolve?: (host: string) => Promise<string[]>;
  now?: () => number;
  sleep?: (ms: number) => Promise<void>;
}

export interface FetchedPage {
  url: string;
  status: number;
  html: string;
  retrievedAt: string;
}

export class FetchRefused extends Error {
  constructor(
    message: string,
    readonly reason: 'scheme' | 'private_network' | 'robots' | 'content_type' | 'too_large' | 'redirects' | 'http_status',
  ) {
    super(message);
  }
}

const MAX_REDIRECTS = 3;

export function isPrivateAddress(ip: string): boolean {
  if (isIP(ip) === 4) {
    const [a, b] = ip.split('.').map(Number) as [number, number];
    return a === 10 || a === 127 || a === 0 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || a >= 224;
  }
  const v6 = ip.toLowerCase();
  if (v6.startsWith('::ffff:')) return isPrivateAddress(v6.slice(7));
  return v6 === '::1' || v6 === '::' || v6.startsWith('fc') || v6.startsWith('fd') || v6.startsWith('fe80') || v6.startsWith('ff');
}

export class SafeFetcher {
  private readonly robots = new Map<string, Promise<Robots>>();
  private readonly lastHit = new Map<string, number>();
  private readonly fetchImpl: typeof fetch;
  private readonly resolve: (host: string) => Promise<string[]>;
  private readonly now: () => number;
  private readonly sleep: (ms: number) => Promise<void>;
  private readonly token: string;

  constructor(private readonly opts: FetchOptions) {
    this.fetchImpl = opts.fetchImpl ?? fetch;
    this.resolve = opts.resolve ?? (async (host) => (await lookup(host, { all: true })).map((r) => r.address));
    this.now = opts.now ?? Date.now;
    this.sleep = opts.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
    this.token = (opts.userAgent.split('/')[0] ?? opts.userAgent).trim().toLowerCase();
  }

  async fetchHtml(rawUrl: string): Promise<FetchedPage> {
    let url = new URL(rawUrl);
    for (let hop = 0; hop <= MAX_REDIRECTS; hop++) {
      await this.assertAllowed(url);
      const res = await this.get(url, 'text/html,application/xhtml+xml;q=0.9');
      if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
        await res.body?.cancel();
        url = new URL(res.headers.get('location') as string, url);
        continue;
      }
      if (!res.ok) {
        await res.body?.cancel();
        throw new FetchRefused(`HTTP ${res.status} for ${url.href}`, 'http_status');
      }
      const type = (res.headers.get('content-type') ?? '').toLowerCase();
      if (!type.includes('text/html') && !type.includes('application/xhtml')) {
        await res.body?.cancel();
        throw new FetchRefused(`Not an HTML page (${type || 'no content type'})`, 'content_type');
      }
      return { url: url.href, status: res.status, html: await this.readCapped(res), retrievedAt: new Date(this.now()).toISOString() };
    }
    throw new FetchRefused(`More than ${MAX_REDIRECTS} redirects`, 'redirects');
  }

  private async assertAllowed(url: URL): Promise<void> {
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      throw new FetchRefused(`Unsupported scheme ${url.protocol}`, 'scheme');
    }
    await this.assertPublicHost(url.hostname);
    const robots = await this.robotsFor(url);
    if (!robots.isAllowed(url.pathname + url.search)) {
      throw new FetchRefused(`robots.txt disallows ${url.pathname}`, 'robots');
    }
  }

  private async assertPublicHost(hostname: string): Promise<void> {
    if (this.opts.allowPrivateNetwork) return;
    const host = hostname.replace(/^\[|\]$/g, '');
    const addresses = isIP(host) ? [host] : await this.resolve(host);
    if (addresses.length === 0 || addresses.some(isPrivateAddress)) {
      throw new FetchRefused(`${hostname} resolves to a non-public address`, 'private_network');
    }
  }

  private robotsFor(url: URL): Promise<Robots> {
    const origin = url.origin;
    let cached = this.robots.get(origin);
    if (!cached) {
      cached = (async () => {
        try {
          const res = await this.get(new URL('/robots.txt', origin), 'text/plain');
          if (res.status >= 400 && res.status < 500) {
            await res.body?.cancel();
            return Robots.allowAll(); // No robots.txt: everything allowed.
          }
          if (!res.ok) {
            await res.body?.cancel();
            return Robots.disallowAll(); // Server error: assume disallowed (RFC 9309 §2.3.1.4).
          }
          return Robots.parse(await this.readCapped(res, 500_000), this.token);
        } catch {
          return Robots.disallowAll();
        }
      })();
      this.robots.set(origin, cached);
    }
    return cached;
  }

  private async get(url: URL, accept: string): Promise<Response> {
    const host = url.host;
    const wait = (this.lastHit.get(host) ?? -Infinity) + this.opts.perHostIntervalMs - this.now();
    if (wait > 0) await this.sleep(wait);
    this.lastHit.set(host, this.now());
    return this.fetchImpl(url.href, {
      method: 'GET',
      redirect: 'manual',
      headers: { 'User-Agent': this.opts.userAgent, Accept: accept },
      signal: AbortSignal.timeout(this.opts.timeoutMs),
    });
  }

  private async readCapped(res: Response, max = this.opts.maxBytes): Promise<string> {
    if (!res.body) return '';
    const reader = res.body.getReader();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > max) {
        await reader.cancel();
        throw new FetchRefused(`Response larger than ${max} bytes`, 'too_large');
      }
      chunks.push(value);
    }
    return new TextDecoder('utf-8').decode(Buffer.concat(chunks));
  }
}
