import type { MetadataRoute } from "next";
import { absoluteUrl } from "@/lib/seo/urls";
import { categoryCounts } from "./articles";
import { AUTHORS, authorPath } from "./authors";
import { DATA_INDEX_PATH, DATA_PAGES, dataPath, publishedDataPages } from "./data-pages";
import type { ArticleSummary, CityDto, LawFirmSummary, LawyerSummary, PracticeAreaDto, RankingSummary, StateDto, StateStatsDto } from "@/types/api";
import { articleEligibility, indexPageIndexable, listingEligibility, MIN_LAWYERS_FOR_HUB_PAGE, profileEligibility, rankingEligibility } from "./eligibility";

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
  articles?: ArticleSummary[];
  /** Statewide figures (API 1.23): the statistics pages that have enough data are listed too. */
  stats?: StateStatsDto | null;
}

export const STATIC_PATHS = ["/", "/methodology/", "/verified/", "/advertising/", "/about/", "/editorial-policy/", "/contact/", "/privacy/", "/terms/", "/disclaimer/", ...AUTHORS.map((a) => authorPath(a.slug)), DATA_INDEX_PATH, ...DATA_PAGES.filter((p) => !p.stats).map((p) => dataPath(p.slug))];

/** Index pages, listed once they link to enough pages (indexPageIndexable). */
export const INDEX_PATHS = { rankings: "/rankings/", states: "/states/", cities: "/cities/", practiceAreas: "/practice-areas/" } as const;

/** Listing pages that are noindex until they list real profiles (see listingEligibility). */
export const LISTING_PATHS = { lawyers: "/lawyers/", lawFirms: "/law-firms/" } as const;

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
  for (const p of publishedDataPages(input.stats ?? null).filter((d) => d.stats)) entries.push(entry(dataPath(p.slug), undefined, 0.6));
  if (listingEligibility(input.lawyers).indexable) entries.push(entry(LISTING_PATHS.lawyers, undefined, 0.6));
  if (listingEligibility(input.lawFirms).indexable) entries.push(entry(LISTING_PATHS.lawFirms, undefined, 0.6));

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
  // Hubs: the CMS decision (Etap G) when present, else the local approximation.
  const hubIndexable = (hub: { eligibility?: { exists: boolean; indexable: boolean } }, match: (l: LawyerSummary) => boolean) =>
    hub.eligibility ? hub.eligibility.exists && hub.eligibility.indexable : realLawyerCount(input.lawyers, match) >= MIN_LAWYERS_FOR_HUB_PAGE;
  for (const state of input.states) {
    if (hubIndexable(state, (l) => l.location?.stateSlug === state.slug)) entries.push(entry(state.path, undefined, 0.6));
  }
  for (const city of input.cities) {
    if (hubIndexable(city, (l) => l.location?.citySlug === city.slug)) entries.push(entry(city.path, undefined, 0.6));
  }
  for (const area of input.practiceAreas) {
    if (hubIndexable(area, (l) => l.practiceAreas.some((p) => p.slug === area.slug))) entries.push(entry(area.path, undefined, 0.6));
  }

  const exists = (hubs: Array<{ eligibility?: { exists: boolean } }>) => hubs.filter((h) => h.eligibility?.exists).length;
  const indexes: Array<[string, number]> = [
    [INDEX_PATHS.rankings, input.rankings.filter((r) => r.path && !r.isThin).length],
    [INDEX_PATHS.states, exists(input.states)],
    [INDEX_PATHS.cities, exists(input.cities)],
    [INDEX_PATHS.practiceAreas, exists(input.practiceAreas)],
  ];
  for (const [path, count] of indexes) if (indexPageIndexable(count)) entries.push(entry(path, undefined, 0.6));

  const articles = (input.articles ?? []).filter((a) => articleEligibility(a).indexable);
  if (articles.length > 0) entries.push(entry("/articles/", undefined, 0.6));
  for (const article of articles) entries.push(entry(article.path, article.updatedAt, 0.6));
  for (const category of categoryCounts(articles)) {
    if (indexPageIndexable(category.count)) entries.push(entry(`/articles/category/${category.slug}/`, undefined, 0.5));
  }

  // De-duplicate by URL (first wins) for safety.
  const seen = new Set<string>();
  return entries.filter((e) => (seen.has(e.url) ? false : (seen.add(e.url), true)));
}
