/**
 * SEO and structured-data audit of rendered HTML (spec §19–20, Phase 8).
 * Pure functions: the live runner (tests/seo-audit.live.test.ts) fetches
 * pages and the sitemap; unit tests feed fixtures.
 *
 * error   → breaks a rule the site promises (missing canonical, invalid
 *           JSON-LD, review markup, noindex page in the sitemap, …)
 * warning → worth fixing, not a release blocker (lengths, missing sitemap entry)
 */

export interface AuditIssue {
  url: string;
  severity: "error" | "warning";
  code: string;
  message: string;
}

export interface PageFacts {
  title: string | null;
  description: string | null;
  canonical: string | null;
  robots: string | null;
  noindex: boolean;
  h1Count: number;
  og: Record<string, string>;
  twitterCard: string | null;
  jsonLd: unknown[];
  jsonLdErrors: string[];
  links: string[];
}

const decode = (s: string): string =>
  s
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#x27;|&#39;/g, "'");

function attrs(tag: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const m of tag.matchAll(/([a-zA-Z:-]+)\s*=\s*"([^"]*)"/g)) out[(m[1] as string).toLowerCase()] = decode(m[2] as string);
  return out;
}

export function extractPage(html: string): PageFacts {
  const head = html.slice(0, html.search(/<\/head>/i) >= 0 ? html.search(/<\/head>/i) : html.length);
  const title = /<title[^>]*>([\s\S]*?)<\/title>/i.exec(head)?.[1] ?? null;
  const metas = [...head.matchAll(/<meta\s[^>]*>/gi)].map((m) => attrs(m[0]));
  const meta = (key: string, value: string): string | null => metas.find((m) => m[key] === value)?.content ?? null;
  const og: Record<string, string> = {};
  for (const m of metas) if (m.property?.startsWith("og:") && m.content !== undefined) og[m.property] = m.content;
  const canonicalTag = [...head.matchAll(/<link\s[^>]*>/gi)].map((m) => attrs(m[0])).find((l) => l.rel === "canonical");
  const robots = meta("name", "robots");

  const jsonLd: unknown[] = [];
  const jsonLdErrors: string[] = [];
  for (const m of html.matchAll(/<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi)) {
    try {
      const parsed = JSON.parse(m[1] as string);
      jsonLd.push(...(Array.isArray(parsed) ? parsed : [parsed]));
    } catch {
      jsonLdErrors.push("JSON-LD block is not valid JSON");
    }
  }
  const links = [...html.matchAll(/<a\s[^>]*href="([^"#]+)"/gi)].map((m) => decode(m[1] as string));
  return {
    title: title === null ? null : decode(title.trim()),
    description: meta("name", "description"),
    canonical: canonicalTag?.href ?? null,
    robots,
    noindex: robots !== null && /noindex/i.test(robots),
    h1Count: (html.match(/<h1[\s>]/gi) ?? []).length,
    og,
    twitterCard: meta("name", "twitter:card"),
    jsonLd,
    jsonLdErrors,
    links,
  };
}

type Node = Record<string, unknown>;

function types(node: Node): string[] {
  const t = node["@type"];
  return (Array.isArray(t) ? t : [t]).filter((x): x is string => typeof x === "string");
}

function hasText(v: unknown): boolean {
  return typeof v === "string" && v.trim() !== "";
}

/** Validate JSON-LD nodes against the rules the site relies on. */
export function validateJsonLd(nodes: unknown[]): string[] {
  const errors: string[] = [];
  const visit = (value: unknown, top: boolean): void => {
    if (Array.isArray(value)) {
      value.forEach((v) => visit(v, false));
      return;
    }
    if (typeof value !== "object" || value === null) return;
    const node = value as Node;
    const t = types(node);
    if (top && node["@context"] !== "https://schema.org") errors.push(`${t.join("/") || "node"}: @context must be https://schema.org`);
    if (top && t.length === 0 && !("@graph" in node)) errors.push("top-level node without @type");
    if (t.includes("AggregateRating") || t.includes("Review") || "aggregateRating" in node || "review" in node) {
      errors.push(`${t.join("/")}: review markup is not allowed (third-party ratings; see ADR-019)`);
    }
    if (t.some((x) => ["Organization", "LegalService", "Person", "WebSite", "Attorney"].includes(x)) && !hasText(node.name)) {
      errors.push(`${t.join("/")}: name is required`);
    }
    if (t.includes("BreadcrumbList")) {
      const items = Array.isArray(node.itemListElement) ? (node.itemListElement as Node[]) : [];
      if (items.length === 0) errors.push("BreadcrumbList: itemListElement is empty");
      items.forEach((item, i) => {
        if (item.position !== i + 1) errors.push(`BreadcrumbList: position ${String(item.position)} should be ${i + 1}`);
        if (!hasText(item.name)) errors.push("BreadcrumbList: every item needs a name");
      });
    }
    if (t.includes("ItemList")) {
      const items = Array.isArray(node.itemListElement) ? (node.itemListElement as Node[]) : [];
      if (items.length === 0) errors.push("ItemList: itemListElement is empty");
      if (items.some((item, i) => item.position !== i + 1)) errors.push("ItemList: positions must be 1..n in order");
    }
    if (t.includes("FAQPage")) {
      const qs = Array.isArray(node.mainEntity) ? (node.mainEntity as Node[]) : [];
      if (qs.length === 0) errors.push("FAQPage: mainEntity is empty");
      for (const q of qs) {
        const answer = q.acceptedAnswer as Node | undefined;
        if (!types(q).includes("Question") || !hasText(q.name) || !answer || !hasText(answer.text)) errors.push("FAQPage: each item needs a Question with name and acceptedAnswer.text");
      }
    }
    if (t.includes("Article")) {
      if (!hasText(node.headline)) errors.push("Article: headline is required");
      if (!hasText(node.datePublished)) errors.push("Article: datePublished is required");
      if (!hasText((node.author as Node | undefined)?.name)) errors.push("Article: author.name is required");
    }
    for (const [key, v] of Object.entries(node)) if (key !== "@context") visit(v, false);
  };
  nodes.forEach((n) => visit(n, true));
  return errors;
}

export interface AuditContext {
  /** URL that was fetched (e.g. http://127.0.0.1:3199/lawyers/x/). */
  url: string;
  /** Public origin the canonical URLs use (e.g. https://lexranked.com). */
  siteOrigin: string;
  inSitemap: boolean;
}

export function auditPage(html: string, ctx: AuditContext): AuditIssue[] {
  const p = extractPage(html);
  const issues: AuditIssue[] = [];
  const add = (severity: AuditIssue["severity"], code: string, message: string): void => {
    issues.push({ url: ctx.url, severity, code, message });
  };
  const path = new URL(ctx.url).pathname;
  const expectedCanonical = ctx.siteOrigin.replace(/\/+$/, "") + path;

  if (!p.title) add("error", "title_missing", "No <title>.");
  else if (p.title.length > 70) add("warning", "title_long", `Title is ${p.title.length} characters (> 70).`);
  if (!p.description) add("error", "description_missing", "No meta description.");
  else if (p.description.length < 50 || p.description.length > 170) add("warning", "description_length", `Description is ${p.description.length} characters (50–170 recommended).`);
  if (!p.robots) add("error", "robots_missing", "No robots meta tag.");
  if (!p.canonical) add("error", "canonical_missing", "No canonical link.");
  else if (!/^https:\/\//.test(p.canonical)) add("error", "canonical_not_absolute", `Canonical ${p.canonical} is not an absolute https URL.`);
  else if (!p.noindex && new URL(p.canonical).pathname !== new URL(expectedCanonical).pathname) add("error", "canonical_mismatch", `Canonical ${p.canonical} does not point at this page.`);
  if (p.h1Count !== 1) add("error", "h1_count", `Page has ${p.h1Count} <h1> elements (expected 1).`);
  for (const key of ["og:title", "og:description", "og:url", "og:image"]) if (!p.og[key]) add("error", "og_missing", `Missing ${key}.`);
  if (!p.twitterCard) add("warning", "twitter_missing", "Missing twitter:card.");
  for (const e of p.jsonLdErrors) add("error", "jsonld_invalid", e);
  if (p.jsonLd.length === 0) add("warning", "jsonld_missing", "No structured data.");
  for (const e of validateJsonLd(p.jsonLd)) add("error", "jsonld_rule", e);
  if (ctx.inSitemap && p.noindex) add("error", "sitemap_noindex", "Page is in the sitemap but noindex.");
  if (!ctx.inSitemap && !p.noindex) add("warning", "not_in_sitemap", "Indexable page is not in the sitemap.");
  return issues;
}

export function sitemapUrls(xml: string): string[] {
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => decode((m[1] as string).trim()));
}
