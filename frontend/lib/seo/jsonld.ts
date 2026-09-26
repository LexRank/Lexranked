import { SITE_DESCRIPTION, SITE_NAME, siteUrl } from "@/lib/config/site";
import type { LawFirmDetail, LawyerDetail, LocationDto, RankingDetail } from "@/types/api";
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
  });
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
    name: ranking.title,
    url: absoluteUrl(path),
    numberOfItems: ranking.entries.length,
    itemListOrder: "https://schema.org/ItemListOrderDescending",
    itemListElement: ranking.entries.map((entry) => ({
      "@type": "ListItem",
      position: entry.position,
      name: entry.entity.name,
      url: absoluteUrl(entry.entity.path),
    })),
  });
}

export function collectionPageJsonLd(name: string, path: string, description: string): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    name,
    url: absoluteUrl(path),
    description,
    isPartOf: { "@id": `${siteUrl}/#website` },
  };
}
