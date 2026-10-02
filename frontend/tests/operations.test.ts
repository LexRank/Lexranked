import { createHmac } from "node:crypto";
import { describe, expect, it } from "vitest";
import { clientKey, RateLimiter } from "@/lib/rateLimit";
import { parsePayload, sign, verifySignature } from "@/lib/revalidate/signature";

const SECRET = "test-secret-with-at-least-thirty-two-bytes!";

describe("revalidation signature", () => {
  it("matches the plugin's test vector", () => {
    // Same vector as wordpress/plugins/lexranked-core/tests/Unit/OperationsTest.php.
    expect(sign("x", 1_700_000_000, '{"a":1}')).toBe(`t=1700000000,v1=${createHmac("sha256", "x").update('1700000000.{"a":1}').digest("hex")}`);
  });

  it("accepts fresh, untampered requests only", () => {
    const body = '{"tags":["lexranked"],"paths":["/"]}';
    const header = sign(SECRET, 1_800_000_000, body);
    expect(verifySignature(SECRET, header, body, 1_800_000_010)).toBe(true);
    expect(verifySignature(SECRET, header, `${body} `, 1_800_000_010)).toBe(false);
    expect(verifySignature(SECRET, header, body, 1_800_000_000 + 301)).toBe(false);
    expect(verifySignature(`${SECRET}x`, header, body, 1_800_000_010)).toBe(false);
    expect(verifySignature(SECRET, null, body, 1_800_000_010)).toBe(false);
    expect(verifySignature("short", sign("short", 1, body), body, 1)).toBe(false);
  });

  it("keeps only known tags and safe paths", () => {
    expect(parsePayload('{"tags":["lexranked","other"],"paths":["/","/lawyers/jane-doe/","//evil","https://x","/a?b=1"]}')).toEqual({
      tags: ["lexranked"],
      paths: ["/", "/lawyers/jane-doe/"],
    });
    expect(parsePayload("not json")).toBeNull();
  });
});

describe("rate limiter", () => {
  it("allows bursts up to the limit and refills over time", () => {
    const limiter = new RateLimiter(3);
    expect([1, 2, 3, 4].map(() => limiter.take("ip", 0))).toEqual([true, true, true, false]);
    expect(limiter.take("ip", 20_000)).toBe(true); // 20 s × 3/min = 1 token.
    expect(limiter.take("other", 0)).toBe(true);
  });

  it("bounds memory", () => {
    const limiter = new RateLimiter(1, 2);
    limiter.take("a", 0);
    limiter.take("b", 0);
    limiter.take("c", 0);
    expect(limiter.take("a", 0)).toBe(true); // "a" was evicted, so it starts fresh.
  });

  it("identifies clients behind proxies", () => {
    expect(clientKey(new Headers({ "cf-connecting-ip": "1.2.3.4", "x-forwarded-for": "9.9.9.9" }))).toBe("1.2.3.4");
    expect(clientKey(new Headers({ "x-forwarded-for": "5.6.7.8, 10.0.0.1" }))).toBe("5.6.7.8");
    expect(clientKey(new Headers())).toBe("unknown");
  });
});
