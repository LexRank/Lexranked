/**
 * Live SEO / structured-data audit of a running site:
 *   AUDIT_BASE_URL=http://127.0.0.1:3000 AUDIT_SITE_URL=https://lexranked.com npm run audit:seo
 * Crawls the sitemap plus internal links (two levels, bounded) and fails
 * on any error-level issue. Skipped when AUDIT_BASE_URL is not set.
 */
import { describe, expect, it } from "vitest";
import { auditPage, sitemapUrls, type AuditIssue } from "@/lib/seo/audit";

const base = (process.env.AUDIT_BASE_URL ?? "").replace(/\/+$/, "");
const site = (process.env.AUDIT_SITE_URL ?? "https://lexranked.com").replace(/\/+$/, "");
const MAX_PAGES = Number(process.env.AUDIT_MAX_PAGES ?? 60);
// Next.js streams metadata into the body for regular browsers on dynamic pages and
// renders it in <head> for crawlers; audit what search engines receive.
const CRAWLER = { headers: { "User-Agent": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" } };
const SKIP = [/^\/search\//, /^\/status\//, /^\/api\//, /\?/];

describe.skipIf(!base)("live SEO audit", () => {
  it("has no error-level SEO or structured-data issues", { timeout: 300_000 }, async () => {
    const sitemap = sitemapUrls(await (await fetch(`${base}/sitemap.xml`, CRAWLER)).text()).map((u) => new URL(u).pathname);
    const inSitemap = new Set(sitemap);
    const queue = [...new Set(["/", ...sitemap])];
    const seen = new Set<string>();
    const issues: AuditIssue[] = [];
    let depth = 0;
    while (queue.length > 0 && seen.size < MAX_PAGES && depth < 3) {
      const level = queue.splice(0, queue.length);
      for (const path of level) {
        if (seen.has(path) || seen.size >= MAX_PAGES || SKIP.some((re) => re.test(path))) continue;
        seen.add(path);
        const res = await fetch(base + path, CRAWLER);
        if (res.status !== 200) {
          issues.push({ url: base + path, severity: "error", code: "status", message: `HTTP ${res.status}` });
          continue;
        }
        const html = await res.text();
        issues.push(...auditPage(html, { url: base + path, siteOrigin: site, inSitemap: inSitemap.has(path) }));
        for (const m of html.matchAll(/<a\s[^>]*href="(\/[^"#?]*)"/g)) {
          const link = m[1] as string;
          if (!seen.has(link)) queue.push(link);
        }
      }
      depth++;
    }
    const errors = issues.filter((i) => i.severity === "error");
    const warnings = issues.filter((i) => i.severity === "warning");
    console.log(`SEO audit: ${seen.size} pages, ${errors.length} errors, ${warnings.length} warnings`);
    for (const w of warnings.slice(0, 30)) console.log(`  warning ${w.code} ${new URL(w.url).pathname}: ${w.message}`);
    expect(errors, errors.map((e) => `${e.code} ${new URL(e.url).pathname}: ${e.message}`).join("\n")).toEqual([]);
    expect(seen.size).toBeGreaterThan(5);
  });
});
