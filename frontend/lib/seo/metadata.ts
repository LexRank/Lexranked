import type { Metadata } from "next";
import { SITE_NAME } from "@/lib/config/site";
import { absoluteUrl } from "./urls";

/**
 * The single place where page metadata is assembled (title, description,
 * canonical, robots, OpenGraph, Twitter/X). Pages pass facts, never raw
 * Metadata objects, so SEO rules stay consistent across the site.
 */

export interface PageSeo {
  /** Page title without the site suffix (the root layout template adds it). */
  title: string;
  description: string;
  /** Site-relative path; becomes the canonical URL. */
  path: string;
  /** Exclude from search engines (demo data, thin or error pages). */
  noindex?: boolean;
  type?: "website" | "profile" | "article";
  /** Use the title verbatim, without the "| LexRanked" suffix. */
  absoluteTitle?: boolean;
  /** Social image (e.g. an article's featured image); defaults to the site image. */
  image?: { url: string; width: number; height: number; alt: string } | null;
  /** Article dates for OpenGraph. */
  publishedTime?: string | null;
  modifiedTime?: string | null;
}

const MAX_DESCRIPTION = 160;
/** Titles longer than this are usually truncated in search results. */
const MAX_TITLE = 60;

/** Collapse whitespace and cut at a word boundary to ~160 characters. */
export function clampDescription(text: string, max = MAX_DESCRIPTION): string {
  const clean = text.replace(/\s+/g, " ").trim();
  if (clean.length <= max) return clean;
  const cut = clean.slice(0, max - 1);
  const lastSpace = cut.lastIndexOf(" ");
  return `${(lastSpace > 60 ? cut.slice(0, lastSpace) : cut).replace(/[\s,.;:–-]+$/, "")}…`;
}

export function buildMetadata(seo: PageSeo): Metadata {
  const url = absoluteUrl(seo.path);
  const description = clampDescription(seo.description);
  // The brand suffix is dropped when it would push the title past what search results show.
  const absolute = seo.absoluteTitle || `${seo.title} | ${SITE_NAME}`.length > MAX_TITLE;
  const fullTitle = absolute ? seo.title : `${seo.title} | ${SITE_NAME}`;
  const robots = seo.noindex
    ? { index: false, follow: true, googleBot: { index: false, follow: true } }
    : { index: true, follow: true, googleBot: { index: true, follow: true, "max-snippet": -1, "max-image-preview": "large" as const } };

  // Default social image (app/opengraph-image.tsx). Set explicitly because a
  // page-level openGraph object replaces the inherited file-based image.
  const images = seo.image
    ? [{ url: seo.image.url, width: seo.image.width, height: seo.image.height, alt: seo.image.alt || seo.title }]
    : [{ url: "/opengraph-image", width: 1200, height: 630, alt: `${SITE_NAME} — Data-driven lawyer rankings` }];

  return {
    title: absolute ? { absolute: seo.title } : seo.title,
    description,
    alternates: { canonical: url },
    robots,
    openGraph: {
      type: seo.type === "profile" ? "profile" : seo.type === "article" ? "article" : "website",
      url,
      title: fullTitle,
      description,
      siteName: SITE_NAME,
      locale: "en_US",
      images,
      ...(seo.type === "article" && seo.publishedTime ? { publishedTime: seo.publishedTime } : {}),
      ...(seo.type === "article" && seo.modifiedTime ? { modifiedTime: seo.modifiedTime } : {}),
    },
    twitter: {
      card: "summary_large_image",
      title: fullTitle,
      description,
      images: images.map((i) => i.url),
    },
  };
}
