import { describe, expect, it } from "vitest";
import { factValue, groupFacts, lastVerified } from "@/lib/content/facts";
import { relatedQuestions } from "@/lib/content/relatedQuestions";
import { lawyerJsonLd } from "@/lib/seo/jsonld";
import type { FactDto } from "@/types/api";
import { lawyerDetail, rankingDetail, rankingSummary } from "./fixtures/api";

const fact = (attribute: string, category: string, value: unknown, status: FactDto["status"] = "verified", verifiedAt: string | null = "2026-09-27T10:00:00Z"): FactDto => ({
  attribute,
  label: attribute,
  category,
  value,
  unit: null,
  status,
  confidence: 0.9,
  source: { id: 1, name: "State Bar", publisher: null, url: null, type: "official_registry", tier: 1, tierLabel: "Official / regulatory" },
  claimCount: 1,
  observedAt: "2026-09-20T10:00:00Z",
  verifiedAt,
  freshness: { category: "profile", maxAgeDays: 90, lastVerifiedAt: verifiedAt, isStale: false, staleAt: null },
  method: null,
});

describe("Sources & Verification (Etap H)", () => {
  it("formats stored values without inventing anything", () => {
    expect(factValue(fact("rating", "reviews", 4.8))).toBe("4.8 / 5");
    expect(factValue(fact("review_count", "reviews", 387))).toBe("387 reviews");
    expect(factValue(fact("case_types", "practice", ["car-accidents"]))).toBe("Car Accidents");
    expect(factValue(fact("practice_areas", "practice", ["personal-injury"]), { "personal-injury": "Personal Injury" })).toBe("Personal Injury");
    expect(factValue(fact("education", "credentials", [{ institution: "Law School", degree: "J.D.", year: "2003" }]))).toBe("J.D., Law School, 2003");
    expect(factValue(fact("website", "contact", null))).toBe("—");
  });

  it("groups facts by category, credentials first, and hides redundant ones", () => {
    const groups = groupFacts([fact("city", "location", "Miami"), fact("bar_status", "credentials", "active"), fact("first_name", "identity", "John")]);
    expect(groups.map((g) => g.key)).toEqual(["credentials", "location"]);
  });

  it("dates the profile by its latest verified fact", () => {
    expect(lastVerified([fact("a", "x", 1, "verified", "2026-09-20T00:00:00Z"), fact("b", "x", 1, "verified", "2026-09-27T00:00:00Z"), fact("c", "x", 1, "unverified", null)])).toBe("2026-09-27T00:00:00Z");
    expect(lastVerified([fact("c", "x", 1, "unverified", null)])).toBeNull();
  });
});

describe("related questions from data (Etap H)", () => {
  it("asks only what the ranking's data answers", () => {
    const ranking = rankingDetail();
    const questions = relatedQuestions(ranking);
    expect(questions[0]!.question).toMatch(/^How were these (lawyers|firms) ranked\?$/);
    expect(questions.map((q) => q.question)).toContain(`Which ${ranking.entityType === "law_firm" ? "firms" : "lawyers"} are verified?`);
    for (const q of questions) expect(q.answer).not.toMatch(/\b(best choice|recommend|should hire)\b/i);
    expect(relatedQuestions({ ...ranking, entries: [] })).toEqual([]);
  });

  it("links narrower rankings that passed their threshold", () => {
    const ranking = rankingDetail();
    const child = rankingSummary(99, {
      path: "/rankings/florida/miami/personal-injury/car-accidents/",
      title: "Best Car Accident Lawyers",
      isThin: false,
      context: {
        type: "case_type",
        value: "car-accidents",
        segment: "car-accidents",
        label: "Car Accidents",
        attribute: "case_types",
        eligibility: { eligible: true, reasons: [], qualified: 5, verified: 4, parentCount: 8, minEntities: 5, minVerified: 3 },
        calculatedAt: null,
        parent: { id: ranking.id, title: ranking.title, path: ranking.path },
      },
    });
    const q = relatedQuestions(ranking, [child, { ...child, id: 100, isThin: true }]).filter((x) => x.link?.href === child.path);
    expect(q).toHaveLength(1);
    expect(q[0]!.question).toMatch(/list car accidents among their case types\?$/);
    expect(q[0]!.answer).toContain("5 of the 8");
  });
});

describe("schema.org mirrors the visible profile (Etap H)", () => {
  it("adds a bar credential only for an active admission", () => {
    expect(lawyerJsonLd(lawyerDetail()).hasCredential).toMatchObject({ credentialCategory: "license", name: "Bar admission (FL)" });
    const inactive = lawyerDetail();
    inactive.professional = { ...inactive.professional, barStatus: "inactive" };
    expect(lawyerJsonLd(inactive).hasCredential).toBeUndefined();
  });
});
