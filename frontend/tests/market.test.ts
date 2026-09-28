import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { MarketStats } from "@/components/MarketStats";
import type { MarketDto } from "@/types/api";

const market = (over: Partial<MarketDto["stats"]> = {}, practice = true): MarketDto => ({
  scope: { location: { slug: "miami", name: "Miami, Florida", type: "city" }, practiceArea: practice ? { slug: "personal-injury", name: "Personal Injury" } : null },
  stats: {
    version: "mkt-1.0",
    lawyers: 127,
    firms: 40,
    verifiedLawyers: 82,
    verifiedFirms: 10,
    demoProfiles: 0,
    averageRating: { value: 4.68, sample: 120 },
    medianReviewCount: { value: 96, sample: 118 },
    medianExperience: { value: 14, sample: 110 },
    mostCommonPractice: { slug: "personal-injury", name: "Personal Injury", count: 127 },
    practiceAreas: [],
    dataVerifiedAt: "2026-09-27T10:00:00Z",
    calculatedAt: "2026-09-28T00:00:00Z",
    notes: [],
    ...over,
  },
  summary: "LexRanked tracks 127 lawyers and 40 law firms in personal injury law in Miami, Florida.",
});

const render = (m: MarketDto) => renderToStaticMarkup(createElement(MarketStats, { market: m, title: "Market statistics" }));

describe("market statistics (Etap I)", () => {
  it("shows the brief's coverage figures as computed by the CMS", () => {
    const html = render(market());
    expect(html).toContain("82 of 127");
    expect(html).toContain("4.7 ★");
    expect(html).toContain("120 lawyers");
    expect(html).toContain("LexRanked tracks 127 lawyers");
    expect(html).not.toContain("Most common practice"); // the scope is already a practice area
  });

  it("shows withheld figures as withheld, never estimated", () => {
    const html = render(market({ averageRating: null, medianReviewCount: null }));
    expect(html.match(/Withheld/g)).toHaveLength(2);
    expect(html).not.toContain("★");
  });

  it("names the most common practice area for place-only markets", () => {
    expect(render(market({}, false))).toContain("Most common practice");
  });

  it("renders nothing for an empty market", () => {
    expect(render(market({ lawyers: 0, firms: 0 }))).toBe("");
  });
});
