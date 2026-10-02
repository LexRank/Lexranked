import "server-only";

import { createHmac, timingSafeEqual } from "node:crypto";

/**
 * Verifies `X-LexRanked-Signature: t=<unix>,v1=<hex>` = HMAC-SHA256 of
 * "<t>.<body>" with the shared REVALIDATE_SECRET — the counterpart of
 * LexRanked\Core\Integration\Signature in the plugin.
 */

export const SIGNATURE_HEADER = "x-lexranked-signature";
export const MAX_AGE_SECONDS = 300;
export const MIN_SECRET_LENGTH = 32;

export function sign(secret: string, timestamp: number, body: string): string {
  return `t=${timestamp},v1=${createHmac("sha256", secret).update(`${timestamp}.${body}`).digest("hex")}`;
}

export function verifySignature(secret: string, header: string | null, body: string, nowSeconds: number): boolean {
  if (secret.length < MIN_SECRET_LENGTH || !header) return false;
  const m = /^t=(\d{1,12}),v1=([0-9a-f]{64})$/.exec(header);
  if (!m) return false;
  const t = Number(m[1]);
  if (Math.abs(nowSeconds - t) > MAX_AGE_SECONDS) return false;
  const expected = Buffer.from(createHmac("sha256", secret).update(`${t}.${body}`).digest("hex"));
  const given = Buffer.from(m[2] as string);
  return expected.length === given.length && timingSafeEqual(expected, given);
}

/** Validated revalidation request. */
export interface RevalidatePayload {
  tags: string[];
  paths: string[];
}

const ALLOWED_TAGS = new Set(["lexranked"]);

export function parsePayload(body: string): RevalidatePayload | null {
  let data: unknown;
  try {
    data = JSON.parse(body);
  } catch {
    return null;
  }
  if (typeof data !== "object" || data === null) return null;
  const { tags, paths } = data as { tags?: unknown; paths?: unknown };
  const tagList = Array.isArray(tags) ? tags.filter((t): t is string => typeof t === "string" && ALLOWED_TAGS.has(t)) : [];
  const pathList = Array.isArray(paths)
    ? paths.filter((p): p is string => typeof p === "string" && /^\/[a-z0-9/_-]{0,200}$/.test(p) && !p.includes("//")).slice(0, 50)
    : [];
  return { tags: [...new Set(tagList)], paths: [...new Set(pathList)] };
}
