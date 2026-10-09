import type { StateStatsDto } from "@/types/api";

/**
 * The /data/ pages: LexRanked's own tables, built from statutes or from the
 * figures of /stats/{state}. `stats` pages exist only when the CMS serves
 * those figures (API 1.23); the others are static and always listed.
 */

export interface DataPage {
  slug: string;
  title: string;
  /** One line for the /data/ index. */
  teaser: string;
  stats: boolean;
  /** Statistics pages: whether the figures are enough to publish the page. */
  ready?: (stats: StateStatsDto) => boolean;
}

export const DATA_PAGES: DataPage[] = [
  {
    slug: "florida-statutes-of-limitations",
    title: "Florida Statutes of Limitations: Every Deadline in One Table",
    teaser: "Every civil, employment, estate and criminal deadline, with the exact statute subsection and the shorter notice deadlines that come first.",
    stats: false,
  },
  {
    slug: "florida-judicial-circuits",
    title: "Florida Courts: All 20 Judicial Circuits and Their Counties",
    teaser: "Which circuit and which district court of appeal hear cases from each of Florida's 67 counties, with the number of judges.",
    stats: false,
  },
  {
    slug: "florida-board-certified-lawyers",
    title: "Board-Certified Lawyers in Florida: Numbers by City and Practice Area",
    teaser: "How many board-certified lawyers LexRanked tracks in each city and practice area, and which certifications they hold.",
    stats: true,
    ready: (s) => s.certified > 0,
  },
  {
    slug: "spanish-speaking-lawyers-florida",
    title: "Spanish-Speaking Lawyers in Florida by City",
    teaser: "The share of ranked lawyers who list Spanish, city by city and practice area by practice area.",
    stats: true,
    ready: (s) => s.spanish > 0,
  },
  {
    slug: "florida-lawyer-languages",
    title: "Languages Spoken by Florida Lawyers",
    teaser: "Every language other than English that the lawyers we track list on their Florida Bar records, with counts.",
    stats: true,
    ready: (s) => s.languages.items.some((l) => l.name !== "English"),
  },
  {
    slug: "florida-lawyer-experience",
    title: "Years in Practice of Florida's Top Lawyers by Practice Area",
    teaser: "Median and range of years in practice for board-certified lawyers, by practice area and city.",
    stats: true,
    ready: (s) => s.experience.sample > 0,
  },
  {
    slug: "florida-lawyers-law-schools",
    title: "Where Florida's Ranked Lawyers Went to Law School",
    teaser: "The law schools most often listed by the board-certified lawyers we track.",
    stats: true,
    ready: (s) => s.schools.sample > 0,
  },
];

export const DATA_INDEX_PATH = "/data/";

/** Smallest group a headline claim ("highest share", "most experienced") may rest on. */
export const MIN_SAMPLE = 20;

/** Pages that can be published now: static ones always, statistics ones when their figures are ready. */
export function publishedDataPages(stats: StateStatsDto | null): DataPage[] {
  return DATA_PAGES.filter((p) => !p.stats || (stats !== null && (p.ready?.(stats) ?? true)));
}

/** Whether one statistics page has figures to show. */
export function dataPageReady(page: DataPage, stats: StateStatsDto | null): stats is StateStatsDto {
  return stats !== null && (page.ready?.(stats) ?? true);
}

export function dataPath(slug: string): string {
  return `${DATA_INDEX_PATH}${slug}/`;
}

export function dataPage(slug: string): DataPage {
  const page = DATA_PAGES.find((p) => p.slug === slug);
  if (!page) throw new Error(`Unknown data page: ${slug}`);
  return page;
}

/** Florida Statutes (current edition) on the Florida Senate website. */
export function flStatute(section: string): string {
  return `https://www.flsenate.gov/Laws/Statutes/2025/${section}`;
}

/** "41%" with no decimals; "0%" for an empty base is never shown (callers check the base). */
export function percent(part: number, whole: number): string {
  return `${Math.round((part / whole) * 100)}%`;
}

/** "October 9, 2026" from an ISO timestamp. */
export function dateLabel(iso: string): string {
  return new Date(iso).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric", timeZone: "UTC" });
}
