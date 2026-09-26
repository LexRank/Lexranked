import Link from "next/link";
import type { CityDto, LawFirmSummary, LawyerSummary, RankingSummary, TermContentDto } from "@/types/api";
import { pluralize } from "@/lib/format";
import type { Crumb } from "@/lib/seo/jsonld";
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
}) {
  const reviewed = formatDate(content?.reviewedAt ?? null);
  const hasDemo = lawyers.some((l) => l.isDemo) || rankings.some((r) => r.isDemo);
  return (
    <>
      <JsonLd data={collectionPageJsonLd(title, path, lead)} />
      {content && (content.reviewedBy || content.reviewedAt) && (
        <JsonLd data={rankingPageJsonLd({ name: title, path, description: content.summary ?? lead, dateModified: null, reviewedBy: content.reviewedBy, reviewedAt: content.reviewedAt })} />
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
        </div>
      </PageHeader>
      <div className="container section layout-sidebar">
        <div className="stack">
          {hasDemo && <DemoNotice />}
          {content?.summary && (
            <section className="overview" aria-label="Summary">
              <p className="overview__summary" style={{ margin: 0 }}>
                {content.summary}
              </p>
            </section>
          )}
          {rankings.length > 0 && (
            <section>
              <h2>Rankings</h2>
              <div className="grid grid--2">
                {rankings.map((r) => (
                  <RankingCard key={r.id} ranking={r} />
                ))}
              </div>
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
