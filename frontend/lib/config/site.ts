/**
 * Public site configuration.
 *
 * Only values that are safe to ship to the browser belong here. Server-only
 * configuration (WordPress credentials, API keys) lives in `server-env.ts`.
 */

export const SITE_NAME = "LexRanked";
export const DEFAULT_SITE_URL = "https://lexranked.com";

export const SITE_DESCRIPTION =
  "Data-driven, source-backed rankings of lawyers and law firms in the United States, built on a transparent and reproducible methodology.";

/**
 * Resolve the canonical site origin. Invalid or non-http(s) values fall back
 * to the production origin so a bad env var can never produce broken
 * canonical URLs. Trailing slashes are removed.
 */
export function resolveSiteUrl(raw: string | undefined): string {
  if (!raw || raw.trim() === "") return DEFAULT_SITE_URL;
  try {
    const url = new URL(raw.trim());
    if (url.protocol !== "https:" && url.protocol !== "http:") {
      return DEFAULT_SITE_URL;
    }
    return url.origin + url.pathname.replace(/\/+$/, "");
  } catch {
    return DEFAULT_SITE_URL;
  }
}

export const siteUrl = resolveSiteUrl(process.env.NEXT_PUBLIC_SITE_URL);
