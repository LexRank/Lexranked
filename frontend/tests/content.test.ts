import { describe, expect, it } from "vitest";
import { hubEligibility, listingEligibility, profileEligibility, rankingEligibility } from "@/lib/content/eligibility";
import { finderOptions, rankingScopeLabel, resolveRanking } from "@/lib/content/rankings";
import { buildSitemap, STATIC_PATHS } from "@/lib/content/sitemap";
import { formatDate, formatLocation, formatRating, formatScore, humanize, initials, pluralize } from "@/lib/format";
import { firmSummary, lawyerSummary, rankingSummary } from "./fixtures/api";

describe("eligibility", () => {
  it("hub pages need enough lawyers, and enough real ones to be indexed", () => {
    const real = [1, 2, 3].map((i) => lawyerSummary(i));
    const demo = [1, 2, 3].map((i) => lawyerSummary(i, { isDemo: true }));
    expect(hubEligibility({ lawyerCount: 2, lawFirmCount: 5 }, real.slice(0, 2))).toEqual({ exists: false, indexable: false });
    expect(hubEligibility({ lawyerCount: 3, lawFirmCount: 0 }, real)).toEqual({ exists: true, indexable: true });
    expect(hubEligibility({ lawyerCount: 3, lawFirmCount: 0 }, demo)).toEqual({ exists: true, indexable: false });
  });

  it("thin rankings do not exist and demo rankings are not indexed", () => {
    expect(rankingEligibility({ isThin: true, indexable: false })).toEqual({ exists: false, indexable: false });
    expect(rankingEligibility({ isThin: false, indexable: false })).toEqual({ exists: true, indexable: false });
    expect(rankingEligibility({ isThin: false, indexable: true })).toEqual({ exists: true, indexable: true });
  });

  it("demo profiles and all-demo listings are not indexed", () => {
    expect(profileEligibility({ isDemo: true }).indexable).toBe(false);
    expect(listingEligibility([]).indexable).toBe(false);
    expect(listingEligibility([{ isDemo: true }, { isDemo: false }]).indexable).toBe(true);
  });
});

describe("ranking resolution", () => {
  const rankings = [rankingSummary(1), rankingSummary(2, {
      slug: "fl-pi",
      path: "/rankings/florida/personal-injury/",
      location: { city: null, citySlug: null, state: "Florida", stateSlug: "florida", stateCode: "FL" },
    }),
  ];

  it("matches canonical location paths (case-insensitive)", () => {
    expect(resolveRanking(["Florida", "miami", "personal-injury"], rankings)).toMatchObject({ kind: "match", ranking: { id: 1 } });
    expect(resolveRanking(["florida", "personal-injury"], rankings)).toMatchObject({ kind: "match", ranking: { id: 2 } });
  });

  it("redirects a bare slug to the canonical path", () => {
    expect(resolveRanking(["fl-pi"], rankings)).toEqual({ kind: "redirect", to: "/rankings/florida/personal-injury/" });
  });

  it("returns none for unknown or overly deep paths", () => {
    expect(resolveRanking(["texas"], rankings)).toEqual({ kind: "none" });
    expect(resolveRanking(["a", "b", "c", "d"], rankings)).toEqual({ kind: "none" });
  });

  it("builds finder options only from rankings that exist", () => {
    const opts = finderOptions([...rankings, rankingSummary(3, { isThin: true, path: "/rankings/texas/" })]);
    expect(opts.map((o) => o.path)).toEqual(["/rankings/florida/personal-injury/", "/rankings/florida/miami/personal-injury/"]);
    expect(opts[0]).toMatchObject({ locationLabel: "Florida" });
    expect(opts[1]).toMatchObject({ locationLabel: "Miami, FL", practiceLabel: "Personal Injury" });
    expect(rankingScopeLabel(rankings[0]!)).toBe("Personal Injury · Miami, FL");
  });
});

describe("sitemap", () => {
  it("contains only indexable pages", () => {
    const lawyers = [lawyerSummary(1), lawyerSummary(2), lawyerSummary(3), lawyerSummary(4, { isDemo: true })];
    const map = buildSitemap({
      lawyers,
      lawFirms: [firmSummary(1), firmSummary(2, { isDemo: true })],
      rankings: [rankingSummary(1), rankingSummary(2, { path: "/rankings/texas/", isThin: true, indexable: false }), rankingSummary(3, { path: "/rankings/florida/", indexable: false, isDemo: true })],
      states: [{ id: 1, slug: "florida", name: "Florida", code: "FL", path: "/states/florida/", cityCount: 1, lawyerCount: 4, lawFirmCount: 2 }],
      cities: [{ id: 2, slug: "miami", name: "Miami", path: "/cities/miami/", state: { slug: "florida", name: "Florida", code: "FL" }, lawyerCount: 4, lawFirmCount: 2 }],
      practiceAreas: [{ id: 3, slug: "personal-injury", name: "Personal Injury", description: "", path: "/practice-areas/personal-injury/", lawyerCount: 4, lawFirmCount: 2 }],
    });
    const urls = map.map((e) => e.url.replace("https://lexranked.com", ""));
    expect(urls).toEqual(
      expect.arrayContaining([
        ...STATIC_PATHS,
        "/rankings/florida/miami/personal-injury/",
        "/lawyers/test-lawyer-1/",
        "/law-firms/test-firm-1/",
        "/states/florida/",
        "/cities/miami/",
        "/practice-areas/personal-injury/",
      ]),
    );
    // One state, one city, one ranking: the index pages are still thin.
    for (const excluded of ["/rankings/texas/", "/rankings/florida/", "/lawyers/test-lawyer-4/", "/law-firms/test-firm-2/", "/search/", "/status/", "/rankings/", "/states/", "/cities/", "/practice-areas/"]) {
      expect(urls).not.toContain(excluded);
    }
    expect(new Set(urls).size).toBe(urls.length);
  });

  it("lists index pages once they link to enough pages", () => {
    const city = (n: number) => ({ id: n, slug: `c${n}`, name: `C${n}`, path: `/cities/c${n}/`, state: { slug: "florida", name: "Florida", code: "FL" }, lawyerCount: 4, lawFirmCount: 0, eligibility: { exists: true, indexable: true, reasons: [] } });
    const urls = (cities: ReturnType<typeof city>[]) =>
      buildSitemap({ lawyers: [], lawFirms: [], rankings: [], states: [], cities, practiceAreas: [] }).map((e) => e.url.replace("https://lexranked.com", ""));
    expect(urls([city(1), city(2)])).not.toContain("/cities/");
    expect(urls([city(1), city(2), city(3)])).toContain("/cities/");
  });

  it("omits hubs whose real lawyers are below the minimum", () => {
    const map = buildSitemap({
      lawyers: [lawyerSummary(1), lawyerSummary(2, { isDemo: true }), lawyerSummary(3, { isDemo: true })],
      lawFirms: [],
      rankings: [],
      states: [{ id: 1, slug: "florida", name: "Florida", code: "FL", path: "/states/florida/", cityCount: 1, lawyerCount: 3, lawFirmCount: 0 }],
      cities: [],
      practiceAreas: [],
    });
    expect(map.map((e) => e.url)).not.toContain("https://lexranked.com/states/florida/");
  });
});

describe("format", () => {
  it("formats values for US readers and hides unknowns", () => {
    expect(formatDate("2026-09-23T19:14:23Z")).toBe("September 23, 2026");
    expect(formatDate(null)).toBeNull();
    expect(formatDate("garbage")).toBeNull();
    expect(formatScore(94.2)).toBe("94.20");
    expect(formatScore(null)).toBeNull();
    expect(formatRating(5)).toBe("5.0");
    expect(pluralize(1, "lawyer")).toBe("1 lawyer");
    expect(pluralize(1200, "review")).toBe("1,200 reviews");
    expect(humanize("bar_status")).toBe("Bar status");
  });

  it("builds monograms and location labels", () => {
    expect(initials("Avery Example (Demo)")).toBe("AE");
    expect(initials("Harbor Example Injury Law (Demo)")).toBe("HI");
    expect(initials("Bayside Sample Legal Group (Demo)", "organization")).toBe("BS");
    expect(initials("Smith & Jones Law Group", "organization")).toBe("SJ");
    expect(initials("Mary Ann Smith")).toBe("MS");
    expect(formatLocation({ city: "Miami", state: "Florida", stateCode: "FL" })).toBe("Miami, FL");
    expect(formatLocation({ city: null, state: "Florida", stateCode: "FL" })).toBe("Florida");
    expect(formatLocation(null)).toBeNull();
  });
});

import { componentsWithWeights, METHODOLOGY_COMPONENTS } from "@/lib/methodology";

describe("methodology weights", () => {
  it("documented defaults sum to 100", () => {
    expect(METHODOLOGY_COMPONENTS.reduce((s, c) => s + c.weight, 0)).toBe(100);
  });

  it("uses API weights when available and falls back otherwise", () => {
    expect(componentsWithWeights(null).map((c) => c.key)).not.toContain("review_strength");
    const merged = componentsWithWeights([{ key: "reputation", weight: 25 }]);
    expect(merged.find((c) => c.key === "reputation")?.weight).toBe(25);
    expect(merged.find((c) => c.key === "experience")?.weight).toBe(30);
  });

  it("leaves out components the active version weights 0 (v1.2: reviews)", () => {
    const v11 = componentsWithWeights([{ key: "review_strength", weight: 20 }]);
    expect(v11.find((c) => c.key === "review_strength")?.weight).toBe(20);
    const v12 = componentsWithWeights([{ key: "review_strength", weight: 0 }]);
    expect(v12.map((c) => c.key)).not.toContain("review_strength");
  });
});
