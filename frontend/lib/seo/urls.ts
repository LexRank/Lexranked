import { siteUrl } from "@/lib/config/site";

/**
 * Build an absolute canonical URL for a site path. Paths always end with a
 * trailing slash to match the site's `trailingSlash: true` routing, so each
 * page has exactly one canonical form. A query string, if present, is kept
 * after the slash (e.g. /lawyers/?page=2).
 */
export function absoluteUrl(path: string, base: string = siteUrl): string {
  const [rawPath = "", query] = path.split("?", 2);
  const clean = ("/" + rawPath.replace(/^\/+/, "")).replace(/\/{2,}/g, "/");
  const withSlash = clean.endsWith("/") || /\.[a-z0-9]+$/i.test(clean) ? clean : `${clean}/`;
  return `${base.replace(/\/+$/, "")}${withSlash}${query ? `?${query}` : ""}`;
}
