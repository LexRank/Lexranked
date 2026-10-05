import "server-only";

/**
 * Best-effort, per-instance token bucket for expensive public routes (search).
 * It limits bursts from one client on one server instance; the durable limit
 * belongs at the edge (Cloudflare rate-limiting rule, see docs/operations.md).
 */

interface Bucket {
  tokens: number;
  updated: number;
}

export class RateLimiter {
  private readonly buckets = new Map<string, Bucket>();

  constructor(
    private readonly perMinute: number,
    private readonly maxKeys = 10_000,
  ) {}

  /** Consume one token for `key`; false when the client is over the limit. */
  take(key: string, now: number = Date.now()): boolean {
    const refill = this.perMinute / 60_000;
    const b = this.buckets.get(key) ?? { tokens: this.perMinute, updated: now };
    b.tokens = Math.min(this.perMinute, b.tokens + (now - b.updated) * refill);
    b.updated = now;
    if (this.buckets.size >= this.maxKeys && !this.buckets.has(key)) {
      // Bound memory: drop the oldest entry (Map keeps insertion order).
      const oldest = this.buckets.keys().next().value;
      if (oldest !== undefined) this.buckets.delete(oldest);
    }
    this.buckets.set(key, b);
    if (b.tokens < 1) return false;
    b.tokens -= 1;
    return true;
  }
}

/** Client IP from proxy headers (Cloudflare first, then the first X-Forwarded-For hop). */
export function clientKey(headers: Headers): string {
  const cf = headers.get("cf-connecting-ip");
  if (cf) return cf.trim();
  const xff = headers.get("x-forwarded-for");
  if (xff) return (xff.split(",")[0] ?? "").trim() || "unknown";
  return headers.get("x-real-ip")?.trim() || "unknown";
}

export const searchLimiter = new RateLimiter(30);

/** Claim form submissions and confirmations: a few per minute per client. */
export const claimLimiter = new RateLimiter(5);

/** Review form submissions and confirmations. */
export const reviewLimiter = new RateLimiter(5);
