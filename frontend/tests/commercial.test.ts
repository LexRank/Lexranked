import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it, vi } from "vitest";
import { LawyerCard, RankingEntry } from "@/components/cards";
import { PlacementBlock, PremiumPanel } from "@/components/commercial/Commercial";
import type { CommercialBlock, PlacementDto } from "@/types/api";
import type { ServerEnv } from "@/lib/config/server-env";
import { HONEYPOT_FIELD, isPlausibleToken, parseClaimForm } from "@/lib/claims/validate";
import { rankingJsonLd } from "@/lib/seo/jsonld";
import { apiRequest, WordPressApiError, type ClientDeps } from "@/lib/wordpress/client";
import { lawyerSummary, rankingDetail } from "./fixtures/api";

function form(values: Record<string, string>): FormData {
  const f = new FormData();
  for (const [k, v] of Object.entries(values)) f.set(k, v);
  return f;
}

const valid = {
  entityType: "lawyer",
  entityId: "42",
  role: "self",
  name: "  Avery   Example ",
  email: "Avery@ExampleLaw.com",
  phone: "(305) 555-0110",
  barState: "fl",
  barNumber: "0123456",
  message: "I am the lawyer.\r\n\r\n\r\n\r\nPlease update my phone.",
  consent: "on",
};

describe("claim form validation", () => {
  it("normalises a valid claim", () => {
    const r = parseClaimForm(form(valid));
    expect(r.ok).toBe(true);
    if (r.ok !== true) return;
    expect(r.data).toMatchObject({ entityId: 42, name: "Avery Example", email: "avery@examplelaw.com", barState: "FL", consent: true });
    expect(r.data.message).toBe("I am the lawyer.\n\nPlease update my phone.");
  });

  it.each([
    [{ consent: "" }, "consent"],
    [{ email: "nope" }, "email"],
    [{ name: "A" }, "name"],
    [{ phone: "call me" }, "phone"],
    [{ barNumber: "" }, "barNumber"],
    [{ barState: "XX" }, "barState"],
    [{ message: "see https://spam.example" }, "message"],
    [{ entityType: "law_firm" }, "role"],
    [{ role: "owner" }, "role"],
  ] as const)("rejects %o", (override, field) => {
    const r = parseClaimForm(form({ ...valid, ...override }));
    expect(r.ok).toBe(false);
    if (r.ok === false) expect(r.errors[field]).toBeTruthy();
  });

  it("lets a firm representative claim without a bar number", () => {
    const r = parseClaimForm(form({ ...valid, entityType: "law_firm", role: "firm_representative", barState: "", barNumber: "" }));
    expect(r.ok).toBe(true);
  });

  it("treats a filled honeypot as a bot", () => {
    expect(parseClaimForm(form({ ...valid, [HONEYPOT_FIELD]: "http://spam" })).ok).toBe("bot");
  });

  it("refuses a form without a valid profile", () => {
    expect(parseClaimForm(form({ ...valid, entityId: "abc" })).ok).toBe(false);
  });

  it("accepts only plausible confirmation tokens", () => {
    expect(isPlausibleToken("a".repeat(43))).toBe(true);
    expect(isPlausibleToken("short")).toBe(false);
    expect(isPlausibleToken("a".repeat(42) + "/")).toBe(false);
    expect(isPlausibleToken(undefined)).toBe(false);
  });
});

describe("POST requests to the CMS", () => {
  const env: ServerEnv = { wordpressApiUrl: "https://wp.example.test/wp-json", wordpressUsername: "api", wordpressAppPassword: "secret pass", allowIndexing: false };

  it("sends JSON, is never cached and is not retried after a server error", async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ code: "x", message: "boom" }), { status: 502 }));
    const deps: ClientDeps = { fetch: fetchMock as unknown as typeof fetch, env, sleep: vi.fn(async () => undefined) };
    await expect(apiRequest("claims", { method: "POST", body: { a: 1 } }, deps)).rejects.toBeInstanceOf(WordPressApiError);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit & { next?: unknown }];
    expect(url).toBe("https://wp.example.test/wp-json/lexranked/v1/claims");
    expect(init.method).toBe("POST");
    expect(init.body).toBe('{"a":1}');
    expect(init.cache).toBe("no-store");
    expect(init.next).toBeUndefined();
    expect((init.headers as Record<string, string>)["Content-Type"]).toBe("application/json");
  });

  it("GET requests keep their retries", async () => {
    const fetchMock = vi.fn(async () => new Response("{}", { status: 503 }));
    const deps: ClientDeps = { fetch: fetchMock as unknown as typeof fetch, env, sleep: vi.fn(async () => undefined) };
    await expect(apiRequest("lawyers", {}, deps)).rejects.toBeInstanceOf(WordPressApiError);
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });
});

describe("separation from the organic ranking", () => {
  const paid: CommercialBlock = { status: "premium", isPaidPlacement: false, claimed: true, premium: true };
  const free: CommercialBlock = { status: "free", isPaidPlacement: false, claimed: false, premium: false };

  it("builds ranking structured data from organic entries only", () => {
    const ranking = rankingDetail();
    const ld = JSON.stringify(rankingJsonLd(ranking, "/rankings/florida/miami/personal-injury/"));
    // The function has no input for placements, so a sponsored profile can never enter the ItemList.
    expect(rankingJsonLd.length).toBe(2);
    for (const entry of ranking.entries) expect(ld).toContain(entry.entity.name);
  });

  it("renders organic cards and ranking entries identically for paying and non-paying profiles", () => {
    const html = (c: CommercialBlock) => renderToStaticMarkup(createElement(LawyerCard, { lawyer: lawyerSummary(1, { commercial: c }) }));
    expect(html(paid)).toBe(html(free));
    const entry = rankingDetail().entries[0]!;
    const row = (c: CommercialBlock) => renderToStaticMarkup(createElement(RankingEntry, { entry: { ...entry, entity: { ...entry.entity, commercial: c } } }));
    expect(row(paid)).toBe(row(free));
  });

  it("labels placement blocks as paid, without position or score", () => {
    const placements: PlacementDto[] = [
      { id: 1, product: "sponsored", label: "Sponsored", isPaidPlacement: true, disclosure: "Paid placement. Not part of the LexRanked ranking.", entity: lawyerSummary(9, { commercial: paid }) },
    ];
    const html = renderToStaticMarkup(createElement(PlacementBlock, { placements, product: "sponsored" }));
    expect(html).toContain("<aside");
    expect(html).toContain("Sponsored · Paid");
    expect(html).toContain("Not part of the LexRanked ranking");
    expect(html).toMatch(/href="\/advertising\/?"/);
    expect(html).not.toMatch(/score__|LexRank score|#1\b/);
    expect(renderToStaticMarkup(createElement(PlacementBlock, { placements: [], product: "featured" }))).toBe("");
  });

  it("marks premium calls to action as sponsored links", () => {
    const html = renderToStaticMarkup(
      createElement(PremiumPanel, { name: "Avery Example", content: { label: "Premium profile", message: "Hello", ctaUrl: "https://example.com/contact", disclosure: "Paid." } }),
    );
    expect(html).toContain('rel="sponsored noopener"');
    expect(html).toContain("Premium profile · Paid");
  });
});
