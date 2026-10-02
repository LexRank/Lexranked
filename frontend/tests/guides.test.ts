import { describe, expect, it } from "vitest";
import { addHeadingIds, categoryCounts, rankingsForArticle, relatedArticles } from "@/lib/content/articles";
import type { ArticleSummary, RankingSummary } from "@/types/api";
import { rankingSummary } from "./fixtures/api";

const article = (id: number, cats: string[], updatedAt = `2026-10-0${id}T00:00:00Z`): ArticleSummary => ({
  id,
  slug: `a${id}`,
  path: `/articles/a${id}/`,
  title: `Article ${id}`,
  excerpt: "",
  author: { name: "LexRanked Editorial Team" },
  publishedAt: updatedAt,
  updatedAt,
  reviewedBy: null,
  reviewedAt: null,
  categories: cats.map((c) => ({ slug: c, name: c.replace(/-/g, " ") })),
  image: null,
  wordCount: 900,
  readingMinutes: 4,
  isThin: false,
  relatedRankingId: null,
  isDemo: false,
});

describe("guides", () => {
  it("builds a table of contents from h2 headings and gives them unique ids", () => {
    const { html, toc } = addHeadingIds('<h2>The short version</h2><p>x</p><h2 id="keep">Fees &amp; costs</h2><h2>The short version</h2><h3>skip</h3>');
    expect(toc).toEqual([
      { id: "the-short-version", label: "The short version" },
      { id: "keep", label: "Fees & costs" },
      { id: "the-short-version-2", label: "The short version" },
    ]);
    expect(html).toContain('<h2 id="the-short-version">');
    expect(html).toContain('<h2 id="keep">');
    expect(html).toContain('<h2 id="the-short-version-2">');
  });

  it("suggests guides from the same category first", () => {
    const current = article(1, ["personal-injury"]);
    const all = [current, article(2, ["lawyer-basics"]), article(3, ["personal-injury"]), article(4, ["car-accidents"]), { ...article(5, ["personal-injury"]), isThin: true, wordCount: 120 }];
    expect(relatedArticles(current, all, 3).map((a) => a.id)).toEqual([3, 4, 2]);
  });

  it("counts categories, ignoring Uncategorized", () => {
    expect(categoryCounts([article(1, ["personal-injury"]), article(2, ["personal-injury", "car-accidents"]), article(3, ["uncategorized"])])).toEqual([
      { slug: "personal-injury", name: "personal injury", count: 2 },
      { slug: "car-accidents", name: "car accidents", count: 1 },
    ]);
  });

  it("leads car-accident guides to personal injury rankings", () => {
    const pi = rankingSummary(1);
    const other: RankingSummary = { ...rankingSummary(2), practiceArea: { slug: "family-law", name: "Family Law" }, path: "/rankings/florida/miami/family-law/" };
    const out = rankingsForArticle(article(1, ["car-accidents"]), [other, pi], null, 2);
    expect(out[0]?.id).toBe(1);
  });
});
