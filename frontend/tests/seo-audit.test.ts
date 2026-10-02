import { describe, expect, it } from "vitest";
import { auditPage, extractPage, sitemapUrls, validateJsonLd } from "@/lib/seo/audit";

const page = (head: string, body = "<h1>Title</h1>") => `<html><head>${head}</head><body>${body}</body></html>`;
const GOOD_HEAD = [
  "<title>Best Personal Injury Lawyers in Miami | LexRanked</title>",
  '<meta name="description" content="The top-ranked personal injury lawyers in Miami, scored with a published, deterministic methodology."/>',
  '<meta name="robots" content="index, follow"/>',
  '<link rel="canonical" href="https://lexranked.com/rankings/florida/miami/"/>',
  '<meta property="og:title" content="t"/><meta property="og:description" content="d"/><meta property="og:url" content="u"/><meta property="og:image" content="i"/>',
  '<meta name="twitter:card" content="summary_large_image"/>',
  '<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://lexranked.com/"}]}</script>',
].join("");
const ctx = { url: "http://127.0.0.1:3199/rankings/florida/miami/", siteOrigin: "https://lexranked.com", inSitemap: true };

describe("SEO audit", () => {
  it("passes a well-formed page", () => {
    expect(auditPage(page(GOOD_HEAD), ctx)).toEqual([]);
  });

  it("extracts head facts", () => {
    const f = extractPage(page(GOOD_HEAD));
    expect(f.canonical).toBe("https://lexranked.com/rankings/florida/miami/");
    expect(f.noindex).toBe(false);
    expect(f.jsonLd).toHaveLength(1);
  });

  it("flags missing basics, wrong canonical and noindex pages in the sitemap", () => {
    const head = GOOD_HEAD.replace('content="index, follow"', 'content="noindex, follow"').replace("/rankings/florida/miami/", "/other/").replace(/<title>.*<\/title>/, "");
    const codes = auditPage(page(head, "<h1>a</h1><h1>b</h1>"), ctx).map((i) => i.code);
    expect(codes).toEqual(expect.arrayContaining(["title_missing", "h1_count", "sitemap_noindex"]));
    const mismatch = auditPage(page(GOOD_HEAD.replace("/rankings/florida/miami/", "/other/")), ctx).map((i) => i.code);
    expect(mismatch).toContain("canonical_mismatch");
    expect(auditPage(page(GOOD_HEAD), { ...ctx, inSitemap: false }).map((i) => i.code)).toEqual(["not_in_sitemap"]);
  });

  it("validates structured data rules", () => {
    expect(validateJsonLd([{ "@context": "https://schema.org", "@type": "FAQPage", mainEntity: [{ "@type": "Question", name: "Q" }] }])).toEqual([
      "FAQPage: each item needs a Question with name and acceptedAnswer.text",
    ]);
    expect(validateJsonLd([{ "@context": "https://schema.org", "@type": "Person", name: "A", aggregateRating: { "@type": "AggregateRating" } }]).join()).toMatch(/review markup/);
    expect(validateJsonLd([{ "@context": "https://schema.org", "@type": "ItemList", itemListElement: [{ position: 2 }] }])).toContain("ItemList: positions must be 1..n in order");
    expect(validateJsonLd([{ "@context": "http://schema.org", "@type": "Article", headline: "h", datePublished: "d", author: { name: "a" } }])).toEqual(["Article: @context must be https://schema.org"]);
    const bad = auditPage(page(GOOD_HEAD + '<script type="application/ld+json">{oops</script>'), ctx).map((i) => i.code);
    expect(bad).toContain("jsonld_invalid");
  });

  it("reads sitemap URLs", () => {
    expect(sitemapUrls("<urlset><url><loc>https://lexranked.com/</loc></url><url><loc>https://lexranked.com/a/?x=1&amp;y=2</loc></url></urlset>")).toEqual([
      "https://lexranked.com/",
      "https://lexranked.com/a/?x=1&y=2",
    ]);
  });
});
