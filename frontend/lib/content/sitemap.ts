import type { MetadataRoute } from "next";
import { absoluteUrl } from "@/lib/seo/urls";
import type { CityDto, LawFirmSummary, LawyerSummary, PracticeAreaDto, RankingSummary, StateDto } from "@/types/api";
import { MIN_LAWYERS_FOR_HUB_PAGE, profileEligibility, rankingEligibility } from "./eligibility";

/**
 * Pure sitemap assembly: only indexable pages. Excludes demo data, thin
 * rankings/hubs, search, status and API routes (spec §20).
 */

export interface SitemapInput {
  lawyers: LawyerSummary[];
  lawFirms: LawFirmSummary[];
  rankings: RankingSummary[];
  states: StateDto[];
  cities: CityDto[];
  practiceAreas: PracticeAreaDto[];
}

export const STATIC_PATHS = ["/", "/methodology/", "/verified/", "/rankings/", "/lawyers/", "/law-firms/", "/states/", "/cities/", "/practice-areas/"];

type Entry = MetadataRoute.Sitemap[number];

function entry(path: string, lastModified?: string | null, priority?: number): Entry {
  return {
    url: absoluteUrl(path),
    ...(lastModified ? { lastModified: new Date(lastModified) } : {}),
    ...(priority !== undefined ? { priority } : {}),
  };
}

/**
 * Hubs are listed only when they meet the minimum and at least that many of
 * their lawyers are real. We approximate "real lawyers in hub" from the
 * lawyer summaries we have.
 */
function realLawyerCount(lawyers: LawyerSummary[], match: (l: LawyerSummary) => boolean): number {
  return lawyers.filter((l) => !l.isDemo && match(l)).length;
}

export function buildSitemap(input: SitemapInput): MetadataRoute.Sitemap {
  const entries: Entry[] = STATIC_PATHS.map((p) => entry(p, undefined, p === "/" ? 1 : 0.6));

  for (const ranking of input.rankings) {
    if (ranking.path && rankingEligibility(ranking).indexable) {
      entries.push(entry(ranking.path, ranking.updatedAt, 0.9));
    }
  }
  for (const lawyer of input.lawyers) {
    if (profileEligibility(lawyer).indexable) entries.push(entry(lawyer.path, lawyer.updatedAt, 0.7));
  }
  for (const firm of input.lawFirms) {
    if (profileEligibility(firm).indexable) entries.push(entry(firm.path, firm.updatedAt, 0.7));
  }
  for (const state of input.states) {
    if (realLawyerCount(input.lawyers, (l) => l.location?.stateSlug === state.slug) >= MIN_LAWYERS_FOR_HUB_PAGE) {
      entries.push(entry(state.path, undefined, 0.6));
    }
  }
  for (const city of input.cities) {
    if (realLawyerCount(input.lawyers, (l) => l.location?.citySlug === city.slug) >= MIN_LAWYERS_FOR_HUB_PAGE) {
      entries.push(entry(city.path, undefined, 0.6));
    }
  }
  for (const area of input.practiceAreas) {
    if (realLawyerCount(input.lawyers, (l) => l.practiceAreas.some((p) => p.slug === area.slug)) >= MIN_LAWYERS_FOR_HUB_PAGE) {
      entries.push(entry(area.path, undefined, 0.6));
    }
  }

  // De-duplicate by URL (first wins) for safety.
  const seen = new Set<string>();
  return entries.filter((e) => (seen.has(e.url) ? false : (seen.add(e.url), true)));
}
