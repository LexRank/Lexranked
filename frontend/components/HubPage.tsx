import Link from "next/link";
import type { CityDto, LawFirmSummary, LawyerSummary, MarketDto, PlacementDto, RankingSummary, TermContentDto } from "@/types/api";
import { MarketStats } from "./MarketStats";
import { PlacementBlock } from "./commercial/Commercial";
import { pluralize } from "@/lib/format";
import type { Crumb, JsonLdObject } from "@/lib/seo/jsonld";
import { collectionPageJsonLd, rankingPageJsonLd } from "@/lib/seo/jsonld";
import { formatDate } from "@/lib/format";
import { EditorialBody, FaqSection } from "./ranking/RankingContent";
import { FirmCard, LawyerCard, RankingCard } from "./cards";
import { JsonLd } from "./JsonLd";
import { MethodologyPanel } from "./Methodology";
import { PageHeader } from "./PageHeader";
import { DemoNotice } from "./ui";

/** Shared layout for state, city and practice-area hub pages (spec §21 internal linking). */
export function HubPage({
  crumbs,
  path,
  eyebrow,
  title,
  lead,
  counts,
  rankings,
  lawyers,
  firms,
  cities,
  lawyersHeading,
  firmsHeading,
  content,
  featured = [],
  groupBy,
  market,
  about = [],
}: {
  crumbs: Crumb[];
  path: string;
  eyebrow: string;
  title: string;
  lead: string;
  counts: { lawyerCount: number; lawFirmCount: number };
  rankings: RankingSummary[];
  lawyers: LawyerSummary[];
  firms: LawFirmSummary[];
  cities?: CityDto[];
  lawyersHeading: string;
  firmsHeading: string;
  /** Editorial summary, guide and FAQ (edited on the term in WordPress). */
  content?: TermContentDto | null;
  /** Labelled paid placements, shown after the editorial lists (never mixed into them). */
  featured?: PlacementDto[];
  /** Internal linking that mirrors the data (spec §39): city/state → practice areas → lawyers, or practice area → cities → lawyers. */
  groupBy?: "practice" | "city";
  /** Market statistics computed by the CMS for this hub (Etap I). */
  market?: MarketDto | null;
  /** schema.org nodes the page is about (place, practice area). */
  about?: Array<JsonLdObject | undefined>;
}) {
  const groups = groupBy ? hubGroups(lawyers, rankings, groupBy) : [];
  const reviewed = formatDate(content?.reviewedAt ?? null);
  const hasDemo = lawyers.some((l) => l.isDemo) || rankings.some((r) => r.isDemo);
  return (
    <>
      <JsonLd
        data={collectionPageJsonLd(title, path, content?.summary ?? lead, {
          about,
          items: [...lawyers, ...firms].map((e) => ({ name: e.name, path: e.path })),
        })}
      />
      {content && (content.reviewedBy || content.reviewedAt) && (
        <JsonLd data={rankingPageJsonLd({ name: title, path, description: content.summary ?? lead, dateModified: null, reviewedBy: content.reviewedBy, reviewedAt: content.reviewedAt, about })} />
      )}
      <PageHeader crumbs={crumbs} eyebrow={eyebrow} title={title} lead={lead}>
        <div className="page-header__meta">
          <span>
            <strong>{counts.lawyerCount}</strong> {counts.lawyerCount === 1 ? "lawyer" : "lawyers"}
          </span>
          <span>
            <strong>{counts.lawFirmCount}</strong> {counts.lawFirmCount === 1 ? "law firm" : "law firms"}
          </span>
          {rankings.length > 0 && <span>{pluralize(rankings.length, "ranking")}</span>}
          {market && market.stats.lawyers > 0 && (
            <span>
              <strong>{market.stats.verifiedLawyers}</strong> with verified professional data
            </span>
          )}
        </div>
      </PageHeader>
      <div className="container section layout-sidebar">
        <div className="stack">
          {hasDemo && <DemoNotice />}
          {(content?.summary || market?.summary) && (
            <section className="overview" aria-label="Summary">
              {market?.summary && (
                <p className="overview__answer" style={{ margin: content?.summary ? undefined : 0 }}>
                  {market.summary}
                </p>
              )}
              {content?.summary && (
                <p className="overview__summary" style={{ margin: 0 }}>
                  {content.summary}
                </p>
              )}
            </section>
          )}
          {rankings.length > 0 && (
            <section>
              <h2>Rankings</h2>
              <div className="grid grid--2">
                {/* Statewide rankings ("Best … Lawyers in Florida") lead: they cover every city. */}
                {[...rankings]
                  .sort((a, b) => Number(Boolean(a.location?.citySlug)) - Number(Boolean(b.location?.citySlug)))
                  .map((r) => (
                    <RankingCard key={r.id} ranking={r} />
                  ))}
              </div>
            </section>
          )}
          {market && <MarketStats market={{ ...market, summary: "" }} title="Market statistics" />}
          {groups.length > 1 && (
            <section aria-labelledby="hub-tree-heading">
              <h2 id="hub-tree-heading">{groupBy === "city" ? "By city" : "By practice area"}</h2>
              <ul className="hub-tree">
                {groups.map((g) => (
                  <li key={g.key}>
                    <Link href={g.href} className="hub-tree__head">
                      {g.label}
                    </Link>
                    <span className="muted"> · {pluralize(g.lawyers.length, "lawyer")}</span>
                    <ul>
                      {g.lawyers.slice(0, 3).map((l) => (
                        <li key={l.id}>
                          <Link href={l.path}>{l.name}</Link>
                        </li>
                      ))}
                    </ul>
                  </li>
                ))}
              </ul>
            </section>
          )}
          {lawyers.length > 0 && (
            <section>
              <div className="section__head" style={{ marginBottom: "1rem" }}>
                <h2>{lawyersHeading}</h2>
              </div>
              <div className="grid grid--2">
                {lawyers.map((l) => (
                  <LawyerCard key={l.id} lawyer={l} />
                ))}
              </div>
            </section>
          )}
          {firms.length > 0 && (
            <section>
              <h2>{firmsHeading}</h2>
              <div className="grid grid--2">
                {firms.map((f) => (
                  <FirmCard key={f.id} firm={f} />
                ))}
              </div>
            </section>
          )}
          <PlacementBlock placements={featured} product="featured" />
          {content && <EditorialBody html={content.body} />}
          {content && <FaqSection items={content.faq} />}
          {content && (content.reviewedBy || reviewed) && (
            <p className="card__meta">
              Editorially reviewed{content.reviewedBy ? ` by ${content.reviewedBy}` : ""}
              {reviewed ? ` on ${reviewed}` : ""}.
            </p>
          )}
        </div>
        <aside className="stack">
          {cities && cities.length > 0 && (
            <div className="card">
              <p className="panel-title">Cities</p>
              <ul className="chips">
                {cities.map((c) => (
                  <li key={c.id}>
                    <Link className="chip" href={c.path}>
                      {c.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <MethodologyPanel compact />
        </aside>
      </div>
    </>
  );
}

interface HubGroup {
  key: string;
  label: string;
  href: string;
  lawyers: LawyerSummary[];
}

/** Groups of the listed lawyers, each linking to the most specific existing page (a ranking when one exists). */
function hubGroups(lawyers: LawyerSummary[], rankings: RankingSummary[], by: "practice" | "city"): HubGroup[] {
  const groups = new Map<string, HubGroup>();
  for (const l of lawyers) {
    const keys =
      by === "practice"
        ? l.practiceAreas.map((p) => ({ key: p.slug, label: p.name }))
        : l.location?.citySlug && l.location.city
          ? [{ key: l.location.citySlug, label: l.location.city }]
          : [];
    for (const k of keys) {
      if (!groups.has(k.key)) {
        const ranking = rankings.find((r) => !r.context && r.path && (by === "practice" ? r.practiceArea?.slug === k.key : r.location?.citySlug === k.key));
        groups.set(k.key, { ...k, href: ranking?.path ?? (by === "practice" ? `/practice-areas/${k.key}/` : `/cities/${k.key}/`), lawyers: [] });
      }
      groups.get(k.key)!.lawyers.push(l);
    }
  }
  return [...groups.values()].sort((a, b) => b.lawyers.length - a.lawyers.length || a.label.localeCompare(b.label));
}
