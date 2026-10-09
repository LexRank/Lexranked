import { SITE_DESCRIPTION, SITE_NAME, siteUrl } from "@/lib/config/site";
import type { ArticleDetail, LawFirmDetail, LawyerDetail, LocationDto, RankingDetail } from "@/types/api";
import { authorBySlug, authorPath, type AuthorProfile } from "@/lib/content/authors";
import { absoluteUrl } from "./urls";

/**
 * schema.org builders. Only facts present in API data are emitted — no
 * invented values. Ratings are deliberately NOT emitted as AggregateRating:
 * they come from third-party platforms, and search-engine guidelines only
 * allow review markup for reviews collected by the site itself.
 */

export type JsonLdObject = Record<string, unknown>;

export interface Crumb {
  name: string;
  path: string;
}

/** Serialize for a <script type="application/ld+json"> block, safe against </script> injection. */
export function serializeJsonLd(data: JsonLdObject | JsonLdObject[]): string {
  return JSON.stringify(data)
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026")
    .replace(/\u2028/g, "\\u2028")
    .replace(/\u2029/g, "\\u2029");
}

/** Drop null/undefined/empty values so the output never contains placeholders. */
export function compact<T extends JsonLdObject>(obj: T): T {
  const out: JsonLdObject = {};
  for (const [key, value] of Object.entries(obj)) {
    if (value === null || value === undefined || value === "") continue;
    if (Array.isArray(value) && value.length === 0) continue;
    out[key] = value;
  }
  return out as T;
}

export function organizationJsonLd(): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "Organization",
    "@id": `${siteUrl}/#organization`,
    name: SITE_NAME,
    url: `${siteUrl}/`,
    description: SITE_DESCRIPTION,
  };
}

export function websiteJsonLd(): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "@id": `${siteUrl}/#website`,
    name: SITE_NAME,
    url: `${siteUrl}/`,
    publisher: { "@id": `${siteUrl}/#organization` },
    inLanguage: "en-US",
    potentialAction: {
      "@type": "SearchAction",
      target: { "@type": "EntryPoint", urlTemplate: `${siteUrl}/search/?q={search_term_string}` },
      "query-input": "required name=search_term_string",
    },
  };
}

export function breadcrumbJsonLd(crumbs: Crumb[]): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: crumbs.map((crumb, i) => ({
      "@type": "ListItem",
      position: i + 1,
      name: crumb.name,
      item: absoluteUrl(crumb.path),
    })),
  };
}

function postalAddress(location: LocationDto | null, extra: { street?: string | null; zip?: string | null } = {}) {
  if (!location && !extra.street) return undefined;
  return compact({
    "@type": "PostalAddress",
    streetAddress: extra.street ?? undefined,
    addressLocality: location?.city ?? undefined,
    addressRegion: location?.stateCode ?? location?.state ?? undefined,
    postalCode: extra.zip ?? undefined,
    addressCountry: "US",
  });
}

export function lawyerJsonLd(lawyer: LawyerDetail): JsonLdObject {
  const url = absoluteUrl(lawyer.path);
  return compact({
    "@context": "https://schema.org",
    "@type": "Person",
    "@id": `${url}#person`,
    name: lawyer.name,
    givenName: lawyer.firstName ?? undefined,
    familyName: lawyer.lastName ?? undefined,
    jobTitle: lawyer.title ?? "Attorney",
    url,
    sameAs: lawyer.contact.website ? [lawyer.contact.website] : undefined,
    telephone: lawyer.contact.phone ?? undefined,
    address: postalAddress(lawyer.location, { zip: lawyer.address.zipCode }),
    worksFor: lawyer.firm
      ? { "@type": "LegalService", name: lawyer.firm.name, url: absoluteUrl(lawyer.firm.path) }
      : undefined,
    knowsAbout: lawyer.practiceAreas.map((p) => p.name),
    knowsLanguage: lawyer.professional.languages,
    alumniOf: lawyer.professional.education
      .filter((e) => e.institution)
      .map((e) => ({ "@type": "EducationalOrganization", name: e.institution })),
    award: lawyer.professional.awards.filter((a) => a.name).map((a) => a.name as string),
    // Only when the page shows an active bar admission (Etap H: schema mirrors visible data).
    hasCredential: credentials(lawyer),
    identifier:
      lawyer.professional.barState && lawyer.professional.barNumber
        ? { "@type": "PropertyValue", propertyID: `${lawyer.professional.barState} bar number`, value: lawyer.professional.barNumber }
        : undefined,
  });
}

/**
 * The bar license (only while active) and board certifications, as shown on
 * the profile. One credential stays a single object; several become a list.
 */
function credentials(lawyer: LawyerDetail): JsonLdObject | JsonLdObject[] | undefined {
  const p = lawyer.professional;
  const out: JsonLdObject[] = [];
  if (p.barState && p.barStatus === "active") {
    out.push(compact({ "@type": "EducationalOccupationalCredential", credentialCategory: "license", name: `Bar admission (${p.barState})`, recognizedBy: barRegulator(p.barState) }));
  }
  for (const a of p.awards) {
    if (!a.name || !/^board certified/i.test(a.name)) continue;
    out.push(
      compact({
        "@type": "EducationalOccupationalCredential",
        credentialCategory: "certification",
        name: a.name,
        recognizedBy: a.issuer ? { "@type": "Organization", name: a.issuer } : undefined,
      }),
    );
  }
  return out.length === 0 ? undefined : out.length === 1 ? out[0] : out;
}

/** State bars LexRanked reads as official sources (shown on profiles as the evidence source). */
const BAR_REGULATORS: Record<string, { name: string; url: string }> = {
  FL: { name: "The Florida Bar", url: "https://www.floridabar.org/" },
};

function barRegulator(state: string): JsonLdObject | undefined {
  const bar = BAR_REGULATORS[state.toUpperCase()];
  return bar ? { "@type": "Organization", name: bar.name, url: bar.url } : undefined;
}

/** Node id of a profile, matching the @id its own page declares. */
export function entityNodeId(entityType: string, path: string): string {
  return `${absoluteUrl(path)}${entityType === "law_firm" ? "#organization" : "#person"}`;
}

/** City (inside its state) or state a page is about. */
export function placeJsonLd(location: Pick<LocationDto, "city" | "state"> | null): JsonLdObject | undefined {
  if (!location?.state) return undefined;
  const state = { "@type": "State", name: location.state, containedInPlace: { "@type": "Country", name: "United States" } };
  return location.city ? { "@type": "City", name: location.city, containedInPlace: state } : state;
}

/** Practice area a page is about, linked to its hub. */
export function practiceAreaJsonLd(area: { name: string; slug: string } | null): JsonLdObject | undefined {
  return area ? { "@type": "DefinedTerm", name: area.name, url: absoluteUrl(`/practice-areas/${area.slug}/`) } : undefined;
}

export function lawFirmJsonLd(firm: LawFirmDetail): JsonLdObject {
  const url = absoluteUrl(firm.path);
  return compact({
    "@context": "https://schema.org",
    "@type": "LegalService",
    "@id": `${url}#organization`,
    name: firm.name,
    url,
    sameAs: firm.contact.website ? [firm.contact.website] : undefined,
    telephone: firm.contact.phone ?? undefined,
    email: firm.contact.email ?? undefined,
    address: postalAddress(firm.location, { street: firm.address.street, zip: firm.address.zipCode }),
    areaServed: firm.location?.city
      ? { "@type": "City", name: firm.location.city }
      : firm.location?.state
        ? { "@type": "State", name: firm.location.state }
        : undefined,
    knowsAbout: firm.practiceAreas.map((p) => p.name),
    employee: firm.lawyers.map((l) => ({ "@type": "Person", name: l.name, url: absoluteUrl(l.path) })),
  });
}

export function rankingJsonLd(ranking: RankingDetail, path: string): JsonLdObject {
  return compact({
    "@context": "https://schema.org",
    "@type": "ItemList",
    "@id": `${absoluteUrl(path)}#list`,
    name: ranking.title,
    url: absoluteUrl(path),
    numberOfItems: ranking.entries.length,
    itemListOrder: "https://schema.org/ItemListOrderDescending",
    itemListElement: ranking.entries.map((entry) => ({
      "@type": "ListItem",
      position: entry.position,
      name: entry.entity.name,
      url: absoluteUrl(entry.entity.path),
      // The same node the profile page declares, so the ranking links into the entity graph.
      item: {
        "@type": entry.entity.type === "law_firm" ? "LegalService" : "Person",
        "@id": entityNodeId(entry.entity.type, entry.entity.path),
        name: entry.entity.name,
        url: absoluteUrl(entry.entity.path),
      },
    })),
  });
}

/**
 * CollectionPage for hubs. `items` lists the profiles shown on the page (in
 * page order) as its main entity; `about` names the place / practice area.
 */
export function collectionPageJsonLd(
  name: string,
  path: string,
  description: string,
  extra: { items?: Array<{ name: string; path: string }>; about?: Array<JsonLdObject | undefined>; dateModified?: string | null } = {},
): JsonLdObject {
  const url = absoluteUrl(path);
  const items = extra.items ?? [];
  return compact({
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    "@id": `${url}#webpage`,
    name,
    url,
    description,
    inLanguage: "en-US",
    isPartOf: { "@id": `${siteUrl}/#website` },
    publisher: { "@id": `${siteUrl}/#organization` },
    about: (extra.about ?? []).filter((a): a is JsonLdObject => a !== undefined),
    dateModified: extra.dateModified ?? undefined,
    mainEntity:
      items.length > 0
        ? {
            "@type": "ItemList",
            numberOfItems: items.length,
            itemListElement: items.map((item, i) => ({ "@type": "ListItem", position: i + 1, name: item.name, url: absoluteUrl(item.path) })),
          }
        : undefined,
  });
}

/** FAQPage for editorial FAQs (plain-text answers only). */
export function faqJsonLd(items: Array<{ question: string; answer: string }>): JsonLdObject | null {
  if (items.length === 0) return null;
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((item) => ({
      "@type": "Question",
      name: item.question,
      acceptedAnswer: { "@type": "Answer", text: item.answer },
    })),
  };
}

/** WebPage wrapper for rankings: freshness and editorial review signals. */
export function rankingPageJsonLd(input: {
  name: string;
  path: string;
  description: string;
  dateModified: string | null;
  reviewedBy: string | null;
  reviewedAt: string | null;
  /** The ranking's ItemList is the page's main entity. */
  hasList?: boolean;
  about?: Array<JsonLdObject | undefined>;
}): JsonLdObject {
  const url = absoluteUrl(input.path);
  return compact({
    "@context": "https://schema.org",
    "@type": "WebPage",
    "@id": `${url}#webpage`,
    name: input.name,
    url,
    mainEntity: input.hasList ? { "@id": `${url}#list` } : undefined,
    about: (input.about ?? []).filter((a): a is JsonLdObject => a !== undefined),
    description: input.description,
    inLanguage: "en-US",
    isPartOf: { "@id": `${siteUrl}/#website` },
    publisher: { "@id": `${siteUrl}/#organization` },
    dateModified: input.dateModified ?? undefined,
    lastReviewed: input.reviewedAt ?? undefined,
    reviewedBy: input.reviewedBy ? { "@type": "Person", name: input.reviewedBy } : undefined,
  });
}

/** Article (editorial guide): author, dates, review and publisher. */
export function articleJsonLd(article: ArticleDetail): JsonLdObject {
  return compact({
    "@context": "https://schema.org",
    "@type": "Article",
    headline: article.title,
    description: article.excerpt,
    url: absoluteUrl(article.path),
    mainEntityOfPage: absoluteUrl(article.path),
    inLanguage: "en-US",
    datePublished: article.publishedAt ?? undefined,
    dateModified: article.updatedAt ?? undefined,
    author: articleAuthor(article.author),
    publisher: { "@id": `${siteUrl}/#organization` },
    image: article.image ? [article.image.url] : undefined,
    wordCount: article.wordCount,
    lastReviewed: article.reviewedAt ?? undefined,
    reviewedBy: article.reviewedBy ? { "@type": "Person", name: article.reviewedBy } : undefined,
    articleSection: article.categories.map((c) => c.name),
    isPartOf: { "@id": `${siteUrl}/#website` },
  });
}

/** The byline as schema.org: a Person with a profile URL when one exists. */
function articleAuthor(author: ArticleDetail["author"]): JsonLdObject {
  const profile = authorBySlug(author.slug);
  if (profile) return { "@type": "Person", "@id": `${absoluteUrl(authorPath(profile.slug))}#person`, name: profile.name, url: absoluteUrl(authorPath(profile.slug)) };
  return { "@type": author.name === "LexRanked Editorial Team" ? "Organization" : "Person", name: author.name };
}

/** ProfilePage for an author, with the Person as main entity. */
export function authorJsonLd(profile: AuthorProfile): JsonLdObject {
  const url = absoluteUrl(authorPath(profile.slug));
  return compact({
    "@context": "https://schema.org",
    "@type": "ProfilePage",
    "@id": `${url}#webpage`,
    url,
    name: `${profile.name}, ${profile.jobTitle} at ${SITE_NAME}`,
    inLanguage: "en-US",
    isPartOf: { "@id": `${siteUrl}/#website` },
    mainEntity: {
      "@type": "Person",
      "@id": `${url}#person`,
      name: profile.name,
      jobTitle: profile.jobTitle,
      description: profile.summary,
      image: absoluteUrl(profile.portrait.src),
      url,
      worksFor: { "@id": `${siteUrl}/#organization` },
    },
  });
}

/** Dataset (data pages): what the figures cover, where they come from and when they were calculated. */
export function datasetJsonLd(input: {
  name: string;
  path: string;
  description: string;
  dateModified: string;
  sources: string[];
  variables: string[];
  spatial?: string;
}): JsonLdObject {
  const url = absoluteUrl(input.path);
  return compact({
    "@context": "https://schema.org",
    "@type": "Dataset",
    "@id": `${url}#dataset`,
    name: input.name,
    description: input.description,
    url,
    isAccessibleForFree: true,
    license: `${siteUrl}/terms/`,
    creator: { "@id": `${siteUrl}/#organization` },
    dateModified: input.dateModified,
    isBasedOn: input.sources,
    variableMeasured: input.variables,
    spatialCoverage: input.spatial ? { "@type": "Place", name: input.spatial } : undefined,
    inLanguage: "en-US",
  });
}
