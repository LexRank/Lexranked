import type { ArticleSummary, PracticeAreaRef, RankingSummary } from "@/types/api";
import { articleEligibility } from "./eligibility";

/**
 * Guide (blog) page helpers: a table of contents from the article's own
 * headings, related guides, category counts and the rankings a guide leads
 * to. All derived from data; nothing is generated.
 */

export interface TocEntry {
  id: string;
  label: string;
}

function slugify(text: string): string {
  return (
    text
      .toLowerCase()
      .normalize("NFKD")
      .replace(/[̀-ͯ]/g, "")
      .replace(/&[a-z#0-9]+;/g, " ")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 60) || "section"
  );
}

function plain(html: string): string {
  return html
    .replace(/<[^>]*>/g, "")
    .replace(/&amp;/g, "&")
    .replace(/&#8217;|&rsquo;/g, "’")
    .replace(/&[a-z#0-9]+;/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

/** Give every <h2> an id (keeping an existing one) and list them for the table of contents. */
export function addHeadingIds(html: string): { html: string; toc: TocEntry[] } {
  const toc: TocEntry[] = [];
  const used = new Set<string>();
  const out = html.replace(/<h2(\s[^>]*)?>([\s\S]*?)<\/h2>/gi, (match, attrs: string | undefined, inner: string) => {
    const label = plain(inner);
    if (!label) return match;
    const existing = attrs?.match(/\sid="([^"]+)"/i)?.[1];
    let id = existing ?? slugify(label);
    for (let n = 2; !existing && used.has(id); n++) id = `${slugify(label)}-${n}`;
    used.add(id);
    toc.push({ id, label });
    return existing ? match : `<h2${attrs ?? ""} id="${id}">${inner}</h2>`;
  });
  return { html: out, toc };
}

const indexable = (a: ArticleSummary) => articleEligibility(a).indexable || a.isDemo;

/** Other guides, same category first, newest first within each group. */
export function relatedArticles(current: ArticleSummary, all: ArticleSummary[], limit = 3): ArticleSummary[] {
  const cats = new Set(current.categories.map((c) => c.slug));
  const date = (a: ArticleSummary) => Date.parse(a.updatedAt ?? a.publishedAt ?? "") || 0;
  return all
    .filter((a) => a.id !== current.id && indexable(a))
    .map((a) => ({ a, shared: a.categories.filter((c) => cats.has(c.slug)).length }))
    .sort((x, y) => y.shared - x.shared || date(y.a) - date(x.a))
    .slice(0, limit)
    .map((x) => x.a);
}

export interface CategoryCount extends PracticeAreaRef {
  count: number;
}

/** Categories that have published guides, most guides first. */
export function categoryCounts(all: ArticleSummary[]): CategoryCount[] {
  const map = new Map<string, CategoryCount>();
  for (const a of all) {
    for (const c of a.categories) {
      if (c.slug === "uncategorized") continue;
      const entry = map.get(c.slug) ?? { ...c, count: 0 };
      entry.count += 1;
      map.set(c.slug, entry);
    }
  }
  return [...map.values()].sort((x, y) => y.count - x.count || x.name.localeCompare(y.name));
}

/**
 * Guide categories that belong to a broader practice area: a car-accident
 * guide leads to personal injury rankings.
 */
const PRACTICE_FOR_CATEGORY: Record<string, string> = {
  "car-accidents": "personal-injury",
  "truck-accidents": "personal-injury",
  "motorcycle-accidents": "personal-injury",
  "pedestrian-accidents": "personal-injury",
  "bicycle-accidents": "personal-injury",
  "wrongful-death": "personal-injury",
  "slip-fall": "personal-injury",
  "premises-liability": "personal-injury",
  "workplace-injury": "personal-injury",
  "product-liability": "personal-injury",
  "medical-malpractice": "personal-injury",
  "dui-dwi": "criminal-defense",
  divorce: "family-law",
  "debt-collection": "bankruptcy",
  foreclosure: "real-estate",
  "landlord-tenant": "real-estate",
  probate: "estate-planning",
};

/** Rankings a guide leads to: its practice area first, then the most recently updated others. */
export function rankingsForArticle(article: Pick<ArticleSummary, "categories">, rankings: RankingSummary[], relatedRankingId: number | null, limit = 3): RankingSummary[] {
  const practices = new Set(article.categories.flatMap((c) => [c.slug, PRACTICE_FOR_CATEGORY[c.slug]].filter((s): s is string => Boolean(s))));
  const usable = rankings.filter((r) => r.path && !r.isThin && !r.context);
  const score = (r: RankingSummary) => (r.id === relatedRankingId ? 2 : r.practiceArea && practices.has(r.practiceArea.slug) ? 1 : 0);
  const date = (r: RankingSummary) => Date.parse(r.updatedAt ?? "") || 0;
  return [...usable].sort((a, b) => score(b) - score(a) || date(b) - date(a)).slice(0, limit);
}
