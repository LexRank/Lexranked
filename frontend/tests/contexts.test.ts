import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { ContextualAttributes } from "@/components/cards";
import { contextualAttributes } from "@/lib/content/contextualAttributes";
import { rankingScopePhrase } from "@/lib/content/rankingFacts";
import { finderOptions, resolveRanking } from "@/lib/content/rankings";
import type { RankingContextDto, RankingEntry } from "@/types/api";
import { rankingDetail, rankingSummary } from "./fixtures/api";

const context = (over: Partial<RankingContextDto> = {}): RankingContextDto => ({
  type: "case_type",
  value: "car-accidents",
  segment: "car-accidents",
  label: "Car Accidents",
  attribute: "case_types",
  eligibility: { eligible: true, reasons: [], qualified: 5, verified: 4, parentCount: 8, minEntities: 5, minVerified: 3 },
  calculatedAt: "2026-09-27T10:00:00Z",
  parent: { id: 1, title: "Best Personal Injury Lawyers in Miami", path: "/rankings/florida/miami/personal-injury/" },
  ...over,
});

const entry = (over: Partial<RankingEntry> = {}): RankingEntry => {
  const base = rankingDetail().entries[0]!;
  return {
    ...base,
    keyFacts: { yearsExperience: 18, barStatus: "active", practiceAreas: ["personal-injury"], awards: 1 },
    qualification: null,
    ...over,
  };
};

describe("contextual rankings (Etap F)", () => {
  it("resolves four-segment context paths and keeps them out of the finder", () => {
    const parent = rankingSummary(1, { path: "/rankings/florida/miami/personal-injury/" });
    const child = rankingSummary(2, { path: "/rankings/florida/miami/personal-injury/car-accidents/", context: context() });
    const result = resolveRanking(["florida", "miami", "personal-injury", "car-accidents"], [parent, child]);
    expect(result.kind === "match" && result.ranking.id).toBe(2);
    expect(resolveRanking(["a", "b", "c", "d", "e"], [parent, child]).kind).toBe("none");
    expect(finderOptions([parent, child]).map((o) => o.path)).toEqual(["/rankings/florida/miami/personal-injury/"]);
  });

  it("names the context in the answer-first scope", () => {
    const base = rankingDetail();
    expect(rankingScopePhrase({ ...base, context: context() })).toMatch(/lawyers in .* who list car accidents among their case types$/);
    expect(rankingScopePhrase({ ...base, context: context({ type: "language", value: "spanish", label: "Spanish-speaking" }) })).toMatch(/^Spanish-speaking /);
    expect(rankingScopePhrase({ ...base, context: context({ type: "client_type", value: "businesses", label: "For businesses" }) })).toMatch(/ serving businesses$/);
    expect(rankingScopePhrase({ ...base, context: null })).not.toMatch(/who list|serving|speaking/);
  });

  it("chooses key attributes by context", () => {
    const plain = contextualAttributes(entry(), null).map((a) => a.key);
    expect(plain[0]).toBe("experience");

    const q = {
      attribute: "case_types",
      value: "car-accidents",
      status: "verified" as const,
      sourceId: 1,
      claimId: 2,
      observedAt: null,
      verifiedAt: null,
      source: { name: "State Bar", url: null, tierLabel: null },
    };
    const car = contextualAttributes(entry({ qualification: q }), context());
    expect(car[0]).toMatchObject({ key: "context", label: "Car Accidents", evidence: "verified", source: "State Bar" });

    const spanish = contextualAttributes(entry({ qualification: { ...q, attribute: "languages", value: "Spanish", status: "unverified" } }), context({ type: "language", value: "spanish", label: "Spanish-speaking" }));
    expect(spanish[0]).toMatchObject({ label: "Speaks Spanish", evidence: "sourced" });
    expect(spanish.length).toBeLessThanOrEqual(3);
  });

  it("never invents a missing attribute", () => {
    const bare = entry({ keyFacts: { yearsExperience: null, barStatus: null, practiceAreas: [], awards: 0 } });
    const picks = contextualAttributes({ ...bare, entity: { ...bare.entity, practiceAreas: [] } }, context());
    expect(picks).toEqual([]);
    expect(renderToStaticMarkup(createElement(ContextualAttributes, { entry: { ...bare, entity: { ...bare.entity, practiceAreas: [] } }, context: context() }))).toBe("");
  });
});
