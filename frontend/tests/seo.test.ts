import { describe, expect, it } from "vitest";
import { buildMetadata, clampDescription } from "@/lib/seo/metadata";
import {
  breadcrumbJsonLd,
  collectionPageJsonLd,
  compact,
  faqJsonLd,
  lawFirmJsonLd,
  lawyerJsonLd,
  placeJsonLd,
  practiceAreaJsonLd,
  rankingJsonLd,
  rankingPageJsonLd,
  serializeJsonLd,
  websiteJsonLd,
} from "@/lib/seo/jsonld";
import { firmSummary, lawyerDetail, lawyerSummary, rankingDetail } from "./fixtures/api";

describe("buildMetadata", () => {
  it("sets canonical, OpenGraph and Twitter from one input", () => {
    const meta = buildMetadata({ title: "Best PI Lawyers", description: "Desc", path: "/rankings/florida/" });
    expect(meta.alternates?.canonical).toBe("https://lexranked.com/rankings/florida/");
    expect(meta.openGraph).toMatchObject({ url: "https://lexranked.com/rankings/florida/", title: "Best PI Lawyers | LexRanked", locale: "en_US", siteName: "LexRanked" });
    expect(meta.twitter).toMatchObject({ card: "summary_large_image", title: "Best PI Lawyers | LexRanked" });
    expect(meta.robots).toMatchObject({ index: true, follow: true });
    expect(meta.openGraph?.images).toEqual([expect.objectContaining({ url: "/opengraph-image", width: 1200, height: 630 })]);
  });

  it("marks demo/thin pages noindex but keeps links followable", () => {
    expect(buildMetadata({ title: "t", description: "d", path: "/x/", noindex: true }).robots).toMatchObject({ index: false, follow: true });
  });

  it("supports absolute titles", () => {
    expect(buildMetadata({ title: "LexRanked — Home", description: "d", path: "/", absoluteTitle: true }).title).toEqual({ absolute: "LexRanked — Home" });
  });
});

describe("clampDescription", () => {
  it("keeps short text and trims long text on a word boundary", () => {
    expect(clampDescription("  short   text ")).toBe("short text");
    const long = clampDescription("word ".repeat(60));
    expect(long.length).toBeLessThanOrEqual(160);
    expect(long.endsWith("…")).toBe(true);
    expect(long).not.toMatch(/\s…$/);
  });
});

describe("JSON-LD", () => {
  it("escapes characters that could break out of the script tag", () => {
    const out = serializeJsonLd({ name: "</script><script>alert(1)</script> & co" });
    expect(out).not.toContain("<");
    expect(out).not.toContain(">");
    expect(JSON.parse(out).name).toBe("</script><script>alert(1)</script> & co");
    expect(serializeJsonLd({ a: "x\u2028y" })).toContain("\\u2028");
  });

  it("compact drops empty values", () => {
    expect(compact({ a: 1, b: null, c: undefined, d: "", e: [], f: [1] })).toEqual({ a: 1, f: [1] });
  });

  it("builds ordered breadcrumbs with absolute URLs", () => {
    const ld = breadcrumbJsonLd([
      { name: "Home", path: "/" },
      { name: "Rankings", path: "/rankings/" },
    ]);
    expect(ld.itemListElement).toEqual([
      { "@type": "ListItem", position: 1, name: "Home", item: "https://lexranked.com/" },
      { "@type": "ListItem", position: 2, name: "Rankings", item: "https://lexranked.com/rankings/" },
    ]);
  });

  it("describes a lawyer as a Person without ratings or invented fields", () => {
    const ld = lawyerJsonLd(lawyerDetail());
    expect(ld).toMatchObject({
      "@type": "Person",
      name: "Test Lawyer 1",
      jobTitle: "Partner",
      worksFor: { "@type": "LegalService", name: "Test Firm" },
      address: { addressLocality: "Miami", addressRegion: "FL", addressCountry: "US", postalCode: "33101" },
      knowsAbout: ["Personal Injury"],
    });
    expect(JSON.stringify(ld)).not.toContain("aggregateRating");
    expect(ld).not.toHaveProperty("award"); // empty list omitted
  });

  it("omits unknown facts instead of emitting placeholders", () => {
    const ld = lawyerJsonLd(
      lawyerDetail({ firm: null, contact: { website: null, phone: null }, professional: { ...lawyerDetail().professional, education: [], languages: [] } }),
    );
    for (const key of ["worksFor", "sameAs", "telephone", "alumniOf", "knowsLanguage"]) {
      expect(ld).not.toHaveProperty(key);
    }
  });

  it("describes firms as LegalService", () => {
    const firm = {
      ...firmSummary(1),
      contact: { website: null, phone: "305-555-0100", email: null },
      address: { street: "1 Test St", zipCode: "33101", country: "US" },
      lawyers: [lawyerSummary(2)],
      description: "",
      freshness: { category: "profile", maxAgeDays: 90, lastVerifiedAt: null, isStale: true, staleAt: null },
      sources: [],
      rankings: [],
      createdAt: null,
    };
    expect(lawFirmJsonLd(firm)).toMatchObject({
      "@type": "LegalService",
      areaServed: { "@type": "City", name: "Miami" },
      employee: [{ "@type": "Person", name: "Test Lawyer 2" }],
    });
  });

  it("describes rankings as an ordered ItemList", () => {
    const ld = rankingJsonLd(rankingDetail(), "/rankings/florida/miami/personal-injury/");
    expect(ld.numberOfItems).toBe(3);
    expect((ld.itemListElement as Array<{ position: number }>).map((i) => i.position)).toEqual([1, 2, 3]);
  });

  it("builds FAQPage only when there are questions", () => {
    expect(faqJsonLd([])).toBeNull();
    expect(faqJsonLd([{ question: "Q?", answer: "A." }])).toMatchObject({
      "@type": "FAQPage",
      mainEntity: [{ "@type": "Question", name: "Q?", acceptedAnswer: { "@type": "Answer", text: "A." } }],
    });
  });

  it("adds freshness and review signals to ranking pages", () => {
    const ld = rankingPageJsonLd({ name: "R", path: "/rankings/x/", description: "d", dateModified: "2026-09-26T00:00:00Z", reviewedBy: "Jane Editor", reviewedAt: "2026-09-20" });
    expect(ld).toMatchObject({ "@type": "WebPage", dateModified: "2026-09-26T00:00:00Z", lastReviewed: "2026-09-20", reviewedBy: { "@type": "Person", name: "Jane Editor" } });
    expect(rankingPageJsonLd({ name: "R", path: "/r/", description: "d", dateModified: null, reviewedBy: null, reviewedAt: null })).not.toHaveProperty("reviewedBy");
  });

  it("links ranking entries to the profile nodes and names what the page is about", () => {
    const ranking = rankingDetail();
    const ld = rankingJsonLd(ranking, "/rankings/florida/miami/personal-injury/");
    const first = ranking.entries[0]!;
    expect(ld["@id"]).toBe("https://lexranked.com/rankings/florida/miami/personal-injury/#list");
    expect((ld.itemListElement as Array<{ item: Record<string, string> }>)[0]!.item).toEqual({
      "@type": "Person",
      "@id": `https://lexranked.com${first.entity.path}#person`,
      name: first.entity.name,
      url: `https://lexranked.com${first.entity.path}`,
    });
    const page = rankingPageJsonLd({
      name: "R",
      path: "/rankings/florida/miami/personal-injury/",
      description: "d",
      dateModified: null,
      reviewedBy: null,
      reviewedAt: null,
      hasList: true,
      about: [placeJsonLd({ city: "Miami", state: "Florida" }), practiceAreaJsonLd({ name: "Personal Injury", slug: "personal-injury" }), undefined],
    });
    expect(page.mainEntity).toEqual({ "@id": "https://lexranked.com/rankings/florida/miami/personal-injury/#list" });
    expect(page.about).toEqual([
      { "@type": "City", name: "Miami", containedInPlace: { "@type": "State", name: "Florida", containedInPlace: { "@type": "Country", name: "United States" } } },
      { "@type": "DefinedTerm", name: "Personal Injury", url: "https://lexranked.com/practice-areas/personal-injury/" },
    ]);
    expect(placeJsonLd(null)).toBeUndefined();
  });

  it("gives hub pages their listed profiles as the main entity", () => {
    const ld = collectionPageJsonLd("Miami", "/cities/miami/", "d", {
      items: [{ name: "A", path: "/lawyers/a/" }, { name: "B", path: "/lawyers/b/" }],
      about: [placeJsonLd({ city: null, state: "Florida" })],
    });
    expect(ld).toMatchObject({
      "@type": "CollectionPage",
      "@id": "https://lexranked.com/cities/miami/#webpage",
      publisher: { "@id": "https://lexranked.com/#organization" },
      about: [{ "@type": "State", name: "Florida" }],
      mainEntity: { "@type": "ItemList", numberOfItems: 2, itemListElement: [{ position: 1, url: "https://lexranked.com/lawyers/a/" }, { position: 2 }] },
    });
    expect(collectionPageJsonLd("X", "/x/", "d")).not.toHaveProperty("mainEntity");
  });

  it("identifies a lawyer by bar number and names the regulator", () => {
    const ld = lawyerJsonLd(lawyerDetail());
    expect(ld.identifier).toEqual({ "@type": "PropertyValue", propertyID: "FL bar number", value: "TEST-1" });
    expect((ld.hasCredential as Record<string, unknown>).recognizedBy).toEqual({ "@type": "Organization", name: "The Florida Bar", url: "https://www.floridabar.org/" });
  });

  it("declares the site search action", () => {
    expect(JSON.stringify(websiteJsonLd())).toContain("/search/?q={search_term_string}");
  });
});
