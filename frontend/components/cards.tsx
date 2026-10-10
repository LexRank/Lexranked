import Link from "next/link";
import { guideImage } from "@/lib/content/articles";
import type { ArticleSummary, LawFirmSummary, LawyerSummary, RankingContextDto, RankingDetail, RankingEntry as RankingEntryDto, RankingSummary } from "@/types/api";
import { contextualAttributes } from "@/lib/content/contextualAttributes";
import { compareHref } from "@/lib/content/compare";
import { formatDate, formatLocation, pluralize } from "@/lib/format";
import { rankingScopeLabel } from "@/lib/content/rankings";
import { DemoBadge, Monogram, ScoreRing, StarRating, VerificationBadge } from "./ui";

export function LawyerCard({ lawyer }: { lawyer: LawyerSummary }) {
  const where = formatLocation(lawyer.location);
  return (
    <article className="card" style={{ display: "flex", gap: "1rem", alignItems: "flex-start" }}>
      <Monogram name={lawyer.name} />
      <div style={{ minWidth: 0, flex: 1 }}>
        <h3 style={{ fontSize: "1.1rem" }}>
          <Link href={lawyer.path} style={{ color: "var(--navy-900)", textDecoration: "none" }}>
            {lawyer.name}
          </Link>
        </h3>
        <p className="card__meta" style={{ margin: "0 0 0.5rem" }}>
          {[lawyer.title, lawyer.firm?.name].filter(Boolean).join(" · ") || "Attorney"}
          {where && (
            <>
              <br />
              {where}
            </>
          )}
        </p>
        <div className="entry__facts" style={{ marginBottom: "0.5rem" }}>
          <VerificationBadge status={lawyer.verification.status} />
          {lawyer.isDemo && <DemoBadge />}
        </div>
        <StarRating rating={lawyer.rating} count={lawyer.reviewCount} />
      </div>
      <ScoreRing score={lawyer.ranking.score} size="sm" />
    </article>
  );
}

export function FirmCard({ firm }: { firm: LawFirmSummary }) {
  const where = formatLocation(firm.location);
  return (
    <article className="card" style={{ display: "flex", gap: "1rem", alignItems: "flex-start" }}>
      <Monogram name={firm.name} square />
      <div style={{ minWidth: 0, flex: 1 }}>
        <h3 style={{ fontSize: "1.1rem" }}>
          <Link href={firm.path} style={{ color: "var(--navy-900)", textDecoration: "none" }}>
            {firm.name}
          </Link>
        </h3>
        <p className="card__meta" style={{ margin: "0 0 0.5rem" }}>
          {[where, firm.lawyerCount > 0 ? pluralize(firm.lawyerCount, "lawyer") : null].filter(Boolean).join(" · ")}
        </p>
        <div className="entry__facts" style={{ marginBottom: "0.5rem" }}>
          <VerificationBadge status={firm.verification.status} />
          {firm.isDemo && <DemoBadge />}
        </div>
        <StarRating rating={firm.rating} count={firm.reviewCount} />
      </div>
      <ScoreRing score={firm.ranking.score} size="sm" />
    </article>
  );
}

export function ArticleCard({ article }: { article: ArticleSummary }) {
  const date = formatDate(article.updatedAt ?? article.publishedAt);
  const image = guideImage(article);
  return (
    <Link href={article.path} className="card card--link card--guide">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img className="card__thumb" src={image.url} width={image.width} height={image.height} alt="" loading="lazy" />
      <p className="eyebrow" style={{ marginBottom: "0.5rem" }}>
        Guide{article.categories[0] ? ` · ${article.categories[0].name}` : ""}
      </p>
      <h3>{article.title}</h3>
      {article.excerpt && <p style={{ margin: "0 0 0.75rem", color: "var(--ink-2)" }}>{article.excerpt}</p>}
      <p className="card__meta" style={{ margin: "0 0 0.75rem" }}>
        {article.readingMinutes} min read{date && <> · Updated {date}</>}
      </p>
      <span className="card__foot">
        <span className="link-arrow" style={{ color: "var(--navy-700)" }}>
          Read the guide
        </span>
        {article.isDemo && <DemoBadge />}
      </span>
    </Link>
  );
}

export function RankingCard({ ranking }: { ranking: RankingSummary }) {
  if (!ranking.path) return null;
  const updated = formatDate(ranking.updatedAt);
  return (
    <Link href={ranking.path} className="card card--link">
      <p className="eyebrow" style={{ marginBottom: "0.5rem" }}>
        {rankingScopeLabel(ranking)}
      </p>
      <h3>{ranking.title}</h3>
      <p className="card__meta" style={{ margin: "0 0 0.75rem" }}>
        {pluralize(ranking.entryCount, ranking.entityType === "law_firm" ? "firm" : "lawyer")} ranked
        {updated && <> · Updated {updated}</>}
      </p>
      <span className="card__foot">
        <span className="link-arrow" style={{ color: "var(--navy-700)" }}>
          View ranking
        </span>
        {ranking.isDemo && <DemoBadge />}
      </span>
    </Link>
  );
}

/** Change since the previous calculation (spec §34). */
export function Movement({ movement, isNew }: { movement: number | null; isNew: boolean }) {
  if (isNew) return <span className="move move--new">New</span>;
  if (movement === null) return null;
  if (movement === 0)
    return (
      <span className="move move--same" title="No change since the previous calculation">
        <span aria-hidden="true">-</span>
        <span className="sr-only">No change</span>
      </span>
    );
  const up = movement > 0;
  return (
    <span className={`move move--${up ? "up" : "down"}`} title={`${up ? "Up" : "Down"} ${Math.abs(movement)} since the previous calculation`}>
      <span aria-hidden="true">{up ? "▲" : "▼"}</span>
      {Math.abs(movement)}
      <span className="sr-only"> {up ? "places up" : "places down"}</span>
    </span>
  );
}

export function RankingEntry({ entry, context }: { entry: RankingEntryDto; context?: RankingContextDto | null }) {
  const e = entry.entity;
  const isLawyer = e.type === "lawyer";
  const where = formatLocation(e.location);
  return (
    <li className={`entry${entry.position === 1 ? " entry--top" : ""}`}>
      <div className="entry__pos" aria-label={`Rank ${entry.position}`}>
        <small aria-hidden="true">Rank</small>
        <span aria-hidden="true">{entry.position}</span>
        <Movement movement={entry.movement} isNew={entry.isNew} />
      </div>
      <div className="entry__main">
        <Monogram name={e.name} square={!isLawyer} />
        <div className="entry__who">
          <h3>
            <Link href={e.path}>{e.name}</Link>
          </h3>
          <p className="entry__firm">
            {isLawyer ? (e as LawyerSummary).firm?.name ?? "Independent practice" : pluralize((e as LawFirmSummary).lawyerCount, "lawyer")}
            {where && (
              <>
                {" · "}
                {where}
              </>
            )}
          </p>
          <div className="entry__facts">
            <StarRating rating={e.rating} count={e.reviewCount} />
            <VerificationBadge status={e.verification.status} />
            {e.isDemo && <DemoBadge />}
          </div>
          <ContextualAttributes entry={entry} context={context} />
        </div>
      </div>
      <div className="entry__side">
        <ScoreRing score={entry.score} />
        <Link href={e.path} className="btn btn--ghost" style={{ minHeight: "2.25rem", padding: "0.4rem 1rem", fontSize: "0.85rem" }}>
          View profile
        </Link>
      </div>
      <WhyRankedHere entry={entry} />
    </li>
  );
}

const fmt = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(1));

/**
 * "Why #N?": a native disclosure (no JavaScript) built only from the stored
 * score components and snapshot differences - never generated text.
 */
export function WhyRankedHere({ entry }: { entry: RankingEntryDto }) {
  const why = entry.why;
  const change = entry.change;
  if (!why) return null;
  return (
    <details className="entry__why">
      <summary>{`Why #${entry.position}?`}</summary>
      <p>{why.summary}</p>
      <div className="entry__why-grid">
        {why.strengths.length > 0 && (
          <div>
            <p className="panel-title">Strongest</p>
            <ul>
              {why.strengths.map((c) => (
                <li key={c.key}>
                  {c.label} <strong>{fmt(c.points)}</strong>/{fmt(c.max)} <span className="muted">(ranking avg {fmt(c.average)})</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        {why.gaps.length > 0 && (
          <div>
            <p className="panel-title">Held back by</p>
            <ul>
              {why.gaps.map((c) => (
                <li key={c.key}>
                  {c.label} <strong>{fmt(c.points)}</strong>/{fmt(c.max)} <span className="muted">(ranking avg {fmt(c.average)})</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
      {why.behind && why.behind.components.length > 0 && (
        <p className="muted">
          {why.behind.scoreGap.toFixed(2)} points behind #{why.behind.position}
          {why.behind.name ? ` (${why.behind.name})` : ""}:{" "}
          {why.behind.components.map((c) => `${c.label} −${fmt(c.delta)}`).join(", ")}.
        </p>
      )}
      {why.missing.length > 0 && <p className="muted">Not on record (scores 0, never estimated): {why.missing.map((m) => m.replace(/_/g, " ")).join(", ")}.</p>}
      {change && change.reasons.length > 0 && (
        <div>
          <p className="panel-title">
            Since the previous calculation
            {change.previousPosition !== null ? ` (was #${change.previousPosition})` : ""}
          </p>
          <ul>
            {change.reasons.slice(0, 6).map((r, i) => (
              <li key={i}>{r.text}</li>
            ))}
          </ul>
        </div>
      )}
    </details>
  );
}

/** Comparison links under a ranking: #1 vs #2, and the top three (Etap E). */
export function CompareLinks({ ranking }: { ranking: RankingDetail }) {
  const type = ranking.entityType === "law_firm" ? "law_firm" : "lawyer";
  const ids = ranking.entries.map((e) => e.entity.entityId);
  const links = [
    { href: compareHref(type, ids.slice(0, 2)), label: "Compare #1 and #2" },
    ...(ids.length >= 3 ? [{ href: compareHref(type, ids.slice(0, 3)), label: "Compare the top 3" }] : []),
  ].filter((l): l is { href: string; label: string } => l.href !== null);
  if (links.length === 0) return null;
  return (
    <nav aria-label="Compare entries">
      <ul className="compare-links">
        {links.map((l) => (
          <li key={l.href}>
            <Link className="link-arrow" href={l.href}>
              {l.label}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** Key attributes chosen by the ranking's context (spec §22-23). */
export function ContextualAttributes({ entry, context }: { entry: RankingEntryDto; context?: RankingContextDto | null }) {
  const attributes = contextualAttributes(entry, context);
  if (attributes.length === 0) return null;
  return (
    <ul className="key-attrs" aria-label="Key attributes">
      {attributes.map((a) => (
        <li key={a.key} className={a.key === "context" ? "key-attrs__item key-attrs__item--context" : "key-attrs__item"} title={a.source ? `Source: ${a.source}` : undefined}>
          {a.evidence === "verified" && <span aria-hidden="true">✓ </span>}
          {a.label}
          {a.key === "context" && a.evidence && <span className="key-attrs__evidence">{a.evidence === "verified" ? " · verified" : " · sourced"}</span>}
        </li>
      ))}
    </ul>
  );
}
