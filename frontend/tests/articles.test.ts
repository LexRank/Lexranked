import { describe, expect, it } from "vitest";
import { articleEligibility, MIN_ARTICLE_WORDS } from "@/lib/content/eligibility";
import { buildSitemap } from "@/lib/content/sitemap";
import { articleJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import type { ArticleDetail } from "@/types/api";

const article: ArticleDetail = {
  id: 9,
  slug: "how-to-choose",
  path: "/articles/how-to-choose/",
  title: "How to choose a personal injury lawyer",
  excerpt: "What to check before you hire.",
  author: { name: "Jane Editor" },
  publishedAt: "2026-09-01T10:00:00Z",
  updatedAt: "2026-09-10T10:00:00Z",
  reviewedBy: "Alex Reviewer",
  reviewedAt: "2026-09-10",
  categories: [{ slug: "guides", name: "Guides" }],
  image: { url: "https://cms.example/img.jpg", width: 1200, height: 630, alt: "Courthouse" },
  wordCount: 850,
  readingMinutes: 4,
  isThin: false,
  relatedRankingId: 3,
  isDemo: false,
  body: "<p>…</p>",
  relatedRanking: { id: 3, title: "Best PI Lawyers in Miami", path: "/rankings/florida/miami/personal-injury/" },
};

const empty = { lawyers: [], lawFirms: [], rankings: [], states: [], cities: [], practiceAreas: [] };

describe("articles", () => {
  it("indexes only real, substantial articles", () => {
    expect(articleEligibility(article)).toEqual({ exists: true, indexable: true });
    expect(articleEligibility({ ...article, wordCount: MIN_ARTICLE_WORDS - 1 }).indexable).toBe(false);
    expect(articleEligibility({ ...article, isDemo: true }).indexable).toBe(false);
  });

  it("adds indexable articles and the guides index to the sitemap", () => {
    const urls = buildSitemap({ ...empty, articles: [article, { ...article, id: 10, path: "/articles/thin/", wordCount: 50 }] }).map((e) => e.url);
    expect(urls).toContain("https://lexranked.com/articles/");
    expect(urls).toContain("https://lexranked.com/articles/how-to-choose/");
    expect(urls).not.toContain("https://lexranked.com/articles/thin/");
    expect(buildSitemap({ ...empty, articles: [{ ...article, isDemo: true }] }).map((e) => e.url)).not.toContain("https://lexranked.com/articles/");
  });

  it("emits Article JSON-LD with author, dates and review", () => {
    const ld = articleJsonLd(article);
    expect(ld).toMatchObject({
      "@type": "Article",
      headline: article.title,
      datePublished: article.publishedAt,
      dateModified: article.updatedAt,
      author: { "@type": "Person", name: "Jane Editor" },
      reviewedBy: { "@type": "Person", name: "Alex Reviewer" },
      image: ["https://cms.example/img.jpg"],
    });
    expect(articleJsonLd({ ...article, author: { name: "LexRanked Editorial Team" }, image: null, reviewedBy: null })).not.toHaveProperty("image");
  });

  it("uses the article image and dates in social metadata", () => {
    const meta = buildMetadata({ title: article.title, description: article.excerpt, path: article.path, type: "article", image: article.image, publishedTime: article.publishedAt });
    expect(meta.openGraph).toMatchObject({ type: "article", publishedTime: article.publishedAt, images: [{ url: "https://cms.example/img.jpg" }] });
  });
});
