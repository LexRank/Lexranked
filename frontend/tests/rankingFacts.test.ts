import { describe, expect, it } from "vitest";
import { rankingAnswer, rankingFacts, rankingScopePhrase } from "@/lib/content/rankingFacts";
import { lawyerSummary, rankingDetail } from "./fixtures/api";

describe("ranking facts", () => {
  it("derives facts only from entries", () => {
    const r = rankingDetail({
      entries: [
        { position: 1, score: 94.21, scoreVersion: "v1.0", movement: null, isNew: false, breakdown: [], entity: lawyerSummary(1, { rating: 4.9, reviewCount: 387 }) },
        { position: 2, score: 90.4, scoreVersion: "v1.0", movement: null, isNew: false, breakdown: [], entity: lawyerSummary(2, { rating: 4.8, reviewCount: 154, verification: { status: "pending", verifiedAt: null, checks: {} } }) },
        { position: 3, score: 88.75, scoreVersion: "v1.0", movement: null, isNew: false, breakdown: [], entity: lawyerSummary(3, { rating: null, reviewCount: null }) },
      ],
    });
    expect(rankingFacts(r)).toEqual({
      count: 3,
      verifiedCount: 2,
      ratedCount: 2,
      averageRating: 4.9, // (4.9 + 4.8) / 2 = 4.85 → 4.9
      totalReviews: 541,
      topNames: [
        { name: "Test Lawyer 1", score: 94.21 },
        { name: "Test Lawyer 2", score: 90.4 },
        { name: "Test Lawyer 3", score: 88.75 },
      ],
    });
  });

  it("writes an answer-first summary from data", () => {
    const text = rankingAnswer(rankingDetail({ updatedAt: "2026-09-26T10:00:00Z" }));
    expect(text).toContain("As of September 26, 2026, the top-ranked personal injury lawyers in Miami, Florida are");
    expect(text).toContain("Test Lawyer 1 (LexRank 94.00), Test Lawyer 2 (93.00) and Test Lawyer 3 (92.00).");
    expect(text).toContain("LexRanked ranked 3 lawyers with the LexRank v1.0 methodology; 3 of them have fully verified profiles.");
    expect(text).toContain("average client rating of 4.5 out of 5 across 30 reviews, for information only");
  });

  it("omits claims it cannot support", () => {
    const r = rankingDetail({
      entries: [{ position: 1, score: 80, scoreVersion: "v1.0", movement: null, isNew: false, breakdown: [], entity: lawyerSummary(1, { rating: null, reviewCount: null, verification: { status: "unverified", verifiedAt: null, checks: {} } }) }],
    });
    const text = rankingAnswer(r);
    expect(text).toContain("the top-ranked personal injury lawyers in Miami, Florida is Test Lawyer 1 (LexRank 80.00).");
    expect(text).toContain("ranked 1 lawyer with");
    expect(text).not.toContain("verified");
    expect(text).not.toContain("rating");
    expect(rankingAnswer(rankingDetail({ entries: [] }))).toBe("");
  });

  it("describes scope for firms and state-level rankings", () => {
    expect(
      rankingScopePhrase({ entityType: "law_firm", practiceArea: null, location: { city: null, citySlug: null, state: "Florida", stateSlug: "florida", stateCode: "FL" } }),
    ).toBe("law firms in Florida");
  });
});
