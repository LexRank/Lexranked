import { describe, expect, it } from "vitest";
import { isIndexingAllowed, parseApiUrl, readServerEnv } from "@/lib/config/server-env";
import { DEFAULT_SITE_URL, resolveSiteUrl } from "@/lib/config/site";
import { absoluteUrl } from "@/lib/seo/urls";

describe("resolveSiteUrl", () => {
  it("falls back to production origin for missing or invalid values", () => {
    expect(resolveSiteUrl(undefined)).toBe(DEFAULT_SITE_URL);
    expect(resolveSiteUrl("")).toBe(DEFAULT_SITE_URL);
    expect(resolveSiteUrl("not a url")).toBe(DEFAULT_SITE_URL);
    expect(resolveSiteUrl("javascript:alert(1)")).toBe(DEFAULT_SITE_URL);
  });

  it("strips trailing slashes", () => {
    expect(resolveSiteUrl("https://preview.lexranked.com/")).toBe("https://preview.lexranked.com");
  });
});

describe("absoluteUrl", () => {
  it("builds canonical URLs with a single trailing slash", () => {
    const base = "https://lexranked.com";
    expect(absoluteUrl("/", base)).toBe("https://lexranked.com/");
    expect(absoluteUrl("lawyers/john-smith", base)).toBe("https://lexranked.com/lawyers/john-smith/");
    expect(absoluteUrl("//rankings//florida/", base)).toBe("https://lexranked.com/rankings/florida/");
    expect(absoluteUrl("/lawyers/?page=2", base)).toBe("https://lexranked.com/lawyers/?page=2");
    expect(absoluteUrl("/lawyers?page=2", base)).toBe("https://lexranked.com/lawyers/?page=2");
    expect(absoluteUrl("/sitemap.xml", base)).toBe("https://lexranked.com/sitemap.xml");
  });
});

describe("server env", () => {
  it("validates WORDPRESS_API_URL", () => {
    expect(parseApiUrl(undefined)).toBeNull();
    expect(parseApiUrl("https://wp.lexranked.com/wp-json/")).toBe("https://wp.lexranked.com/wp-json");
    expect(() => parseApiUrl("wp.lexranked.com")).toThrow();
    expect(() => parseApiUrl("ftp://wp.lexranked.com")).toThrow();
  });

  it("keeps indexing off unless explicitly enabled outside previews", () => {
    expect(isIndexingAllowed({})).toBe(false);
    expect(isIndexingAllowed({ ALLOW_INDEXING: "true" })).toBe(true);
    expect(isIndexingAllowed({ ALLOW_INDEXING: "true", VERCEL_ENV: "production" })).toBe(true);
    expect(isIndexingAllowed({ ALLOW_INDEXING: "true", VERCEL_ENV: "preview" })).toBe(false);
    expect(isIndexingAllowed({ ALLOW_INDEXING: "1" })).toBe(false);
  });

  it("treats blank credentials as absent", () => {
    const env = readServerEnv({ WORDPRESS_USERNAME: "  ", WORDPRESS_APP_PASSWORD: "" });
    expect(env.wordpressUsername).toBeNull();
    expect(env.wordpressAppPassword).toBeNull();
    expect(env.wordpressApiUrl).toBeNull();
  });
});
