import { describe, expect, it } from "vitest";
import { compareHref, parseCompareQuery } from "@/lib/content/compare";

describe("comparison URLs (Etap E)", () => {
  it("parses repeated lawyer or firm IDs", () => {
    expect(parseCompareQuery({ lawyer: ["45", "46"] })).toEqual({ ok: true, type: "lawyer", ids: [45, 46] });
    expect(parseCompareQuery({ firm: ["7", "9", "11"] })).toEqual({ ok: true, type: "law_firm", ids: [7, 9, 11] });
  });

  it("de-duplicates and enforces 2–4 entities", () => {
    expect(parseCompareQuery({ lawyer: ["45", "45"] })).toEqual({ ok: false, reason: "count" });
    expect(parseCompareQuery({ lawyer: "45" })).toEqual({ ok: false, reason: "count" });
    expect(parseCompareQuery({ lawyer: ["1", "2", "3", "4", "5"] })).toEqual({ ok: false, reason: "count" });
  });

  it("rejects empty, mixed and malformed queries", () => {
    expect(parseCompareQuery({})).toEqual({ ok: false, reason: "empty" });
    expect(parseCompareQuery({ lawyer: "1", firm: "2" })).toEqual({ ok: false, reason: "mixed" });
    expect(parseCompareQuery({ lawyer: ["1", "abc"] })).toEqual({ ok: false, reason: "invalid" });
    expect(parseCompareQuery({ lawyer: ["0", "2"] })).toEqual({ ok: false, reason: "invalid" });
    expect(parseCompareQuery({ lawyer: ["1", "2;DROP"] })).toEqual({ ok: false, reason: "invalid" });
  });

  it("builds links only for enough distinct stable IDs", () => {
    expect(compareHref("lawyer", [45, 46])).toBe("/compare/?lawyer=45&lawyer=46");
    expect(compareHref("law_firm", [7, 9, 7])).toBe("/compare/?firm=7&firm=9");
    expect(compareHref("lawyer", [45, null])).toBeNull();
    expect(compareHref("lawyer", [45, 45])).toBeNull();
    expect(compareHref("lawyer", [1, 2, 3, 4, 5])).toBe("/compare/?lawyer=1&lawyer=2&lawyer=3&lawyer=4");
  });

  it("round-trips", () => {
    const href = compareHref("law_firm", [3, 8]) as string;
    const params = new URLSearchParams(href.split("?")[1]);
    expect(parseCompareQuery({ firm: params.getAll("firm") })).toEqual({ ok: true, type: "law_firm", ids: [3, 8] });
  });
});

describe("comparison entry points", () => {
  it("links the top of a ranking by stable entity ID", async () => {
    const { createElement } = await import("react");
    const { renderToStaticMarkup } = await import("react-dom/server");
    const { CompareLinks } = await import("@/components/cards");
    const { rankingDetail } = await import("./fixtures/api");
    const ranking = rankingDetail();
    const entries = ranking.entries.map((e, i) => ({ ...e, entity: { ...e.entity, entityId: 100 + i } }));
    const html = renderToStaticMarkup(createElement(CompareLinks, { ranking: { ...ranking, entries } }));
    // next/link applies trailingSlash from next.config at runtime; the test env has no config.
    expect(html).toMatch(/href="\/compare\/?\?lawyer=100&amp;lawyer=101"/);
    if (entries.length >= 3) expect(html).toContain("Compare the top 3");

    const none = renderToStaticMarkup(createElement(CompareLinks, { ranking: { ...ranking, entries: entries.slice(0, 1) } }));
    expect(none).toBe("");
  });
});
