import { siteUrl } from "@/lib/config/site";

/**
 * Build an absolute canonical URL for a site path. Paths always end with a
 * trailing slash to match the site's `trailingSlash: true` routing, so each
 * page has exactly one canonical form.
 */
export function absoluteUrl(path: string, base: string = siteUrl): string {
  const clean = ("/" + path.replace(/^\/+/, "")).replace(/\/{2,}/g, "/");
  const withSlash = clean.endsWith("/") ? clean : `${clean}/`;
  return `${base.replace(/\/+$/, "")}${withSlash}`;
}
