import type { LawFirmSummary, LawyerDetail, LawyerSummary, RankingDetail, RankingSummary } from "@/types/api";

/** MOCK DTOs for unit tests only. Not real lawyers or firms. */

const location = { city: "Miami", citySlug: "miami", state: "Florida", stateSlug: "florida", stateCode: "FL" };
const verification = { status: "verified" as const, verifiedAt: "2026-09-01T00:00:00Z", checks: {} };

export function lawyerSummary(id: number, overrides: Partial<LawyerSummary> = {}): LawyerSummary {
  return {
    id,
    type: "lawyer",
    slug: `test-lawyer-${id}`,
    path: `/lawyers/test-lawyer-${id}/`,
    name: `Test Lawyer ${id}`,
    firstName: "Test",
    lastName: `Lawyer${id}`,
    title: null,
    firm: null,
    location,
    practiceAreas: [{ slug: "personal-injury", name: "Personal Injury" }],
    rating: 4.5,
    reviewCount: 10,
    ranking: { score: 80 + id, scoreVersion: "v1.0", calculatedAt: null },
    commercial: { status: "free", isPaidPlacement: false },
    verification,
    isDemo: false,
    updatedAt: "2026-09-20T00:00:00Z",
    ...overrides,
  };
}

export function lawyerDetail(overrides: Partial<LawyerDetail> = {}): LawyerDetail {
  return {
    ...lawyerSummary(1),
    title: "Partner",
    firm: { id: 9, slug: "test-firm", name: "Test Firm", path: "/law-firms/test-firm/" },
    contact: { website: "https://example.com/test", phone: "305-555-0100" },
    address: { zipCode: "33101", country: "US" },
    professional: {
      yearsExperience: 10,
      barState: "FL",
      barNumber: "TEST-1",
      barStatus: "active",
      education: [{ institution: "Test Law School", degree: "J.D.", year: "2010" }],
      awards: [],
      languages: ["English"],
    },
    bio: "<p>Bio</p>",
    freshness: { category: "profile", maxAgeDays: 90, lastVerifiedAt: null, isStale: true, staleAt: null },
    sources: [],
    createdAt: null,
    ...overrides,
  };
}

export function firmSummary(id: number, overrides: Partial<LawFirmSummary> = {}): LawFirmSummary {
  return {
    id,
    type: "law_firm",
    slug: `test-firm-${id}`,
    path: `/law-firms/test-firm-${id}/`,
    name: `Test Firm ${id}`,
    location,
    practiceAreas: [],
    rating: null,
    reviewCount: null,
    lawyerCount: 2,
    ranking: { score: null, scoreVersion: null, calculatedAt: null },
    commercial: { status: "free", isPaidPlacement: false },
    verification,
    isDemo: false,
    updatedAt: "2026-09-20T00:00:00Z",
    ...overrides,
  };
}

export function rankingSummary(id: number, overrides: Partial<RankingSummary> = {}): RankingSummary {
  return {
    id,
    slug: `best-pi-miami-${id}`,
    path: "/rankings/florida/miami/personal-injury/",
    title: "Best Personal Injury Lawyers in Miami, Florida",
    entityType: "lawyer",
    location,
    practiceArea: { slug: "personal-injury", name: "Personal Injury" },
    scoreVersion: "v1.0",
    entryCount: 5,
    minEntities: 5,
    isThin: false,
    indexable: true,
    isDemo: false,
    updatedAt: "2026-09-23T00:00:00Z",
    methodologyUrl: "/methodology/",
    ...overrides,
  };
}

export function rankingDetail(overrides: Partial<RankingDetail> = {}): RankingDetail {
  const entries = [1, 2, 3].map((i) => ({
    position: i,
    score: 95 - i,
    scoreVersion: "v1.0",
    entity: lawyerSummary(i),
  }));
  return { ...rankingSummary(1), intro: "", entries, ...overrides };
}
