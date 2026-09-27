import { describe, expect, it } from "vitest";
import { articleEligibility, hubEligibility, profileEligibility, rankingEligibility } from "@/lib/content/eligibility";
import { buildSitemap } from "@/lib/content/sitemap";
import { lawyerSummary, rankingSummary } from "./fixtures/api";

const decided = (exists: boolean, indexable: boolean) => ({ exists, indexable, reasons: exists ? [] : ["No page: published lawyers 1 (needs 3)."] });

describe("page eligibility from the CMS (Etap G)", () => {
  it("prefers the CMS decision over the local fallback", () => {
    // Local rule would say "exists" (5 lawyers); the CMS says no.
    expect(hubEligibility({ lawyerCount: 5, lawFirmCount: 0, eligibility: decided(false, false) }, [])).toEqual({ exists: false, indexable: false });
    // Local rule would say "not indexable" (demo); the CMS decides.
    expect(profileEligibility({ isDemo: true, eligibility: decided(true, true) })).toEqual({ exists: true, indexable: true });
    expect(rankingEligibility({ isThin: false, indexable: true, eligibility: decided(true, false) })).toEqual({ exists: true, indexable: false });
    expect(articleEligibility({ isDemo: false, wordCount: 900, eligibility: decided(true, false) }).indexable).toBe(false);
  });

  it("never indexes a page that does not exist", () => {
    expect(profileEligibility({ isDemo: false, eligibility: decided(false, true) })).toEqual({ exists: false, indexable: false });
  });

  it("falls back to the local rules for older APIs", () => {
    expect(hubEligibility({ lawyerCount: 3, lawFirmCount: 0 }, [{ isDemo: false }, { isDemo: false }, { isDemo: false }])).toEqual({ exists: true, indexable: true });
    expect(profileEligibility({ isDemo: true })).toEqual({ exists: true, indexable: false });
  });

  it("builds the sitemap from the same decisions", () => {
    const real = lawyerSummary(1, { isDemo: false, eligibility: decided(true, false) });
    const map = buildSitemap({
      lawyers: [real],
      lawFirms: [],
      rankings: [rankingSummary(9, { path: "/rankings/texas/houston/", isThin: false, indexable: true, eligibility: decided(true, false) })],
      states: [{ id: 1, slug: "texas", name: "Texas", code: "TX", path: "/states/texas/", cityCount: 1, lawyerCount: 9, lawFirmCount: 0, eligibility: decided(true, true) } as never],
      cities: [{ id: 2, slug: "houston", name: "Houston", path: "/cities/houston/", lawyerCount: 9, lawFirmCount: 0, eligibility: decided(true, false) } as never],
      practiceAreas: [],
    }).map((e) => new URL(e.url).pathname);
    expect(map).toContain("/states/texas/");
    expect(map).not.toContain("/cities/houston/");
    expect(map).not.toContain("/rankings/texas/houston/");
    expect(map).not.toContain(real.path);
  });
});
