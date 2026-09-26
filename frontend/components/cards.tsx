import Link from "next/link";
import type { LawFirmSummary, LawyerSummary, RankingEntry as RankingEntryDto, RankingSummary } from "@/types/api";
import { formatDate, formatLocation, pluralize } from "@/lib/format";
import { rankingScopeLabel } from "@/lib/content/rankings";
import { CommercialBadge, DemoBadge, Monogram, ScoreRing, StarRating, VerificationBadge } from "./ui";

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
          <CommercialBadge commercial={lawyer.commercial} />
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
          <CommercialBadge commercial={firm.commercial} />
          {firm.isDemo && <DemoBadge />}
        </div>
        <StarRating rating={firm.rating} count={firm.reviewCount} />
      </div>
      <ScoreRing score={firm.ranking.score} size="sm" />
    </article>
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
        <span aria-hidden="true">–</span>
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

export function RankingEntry({ entry }: { entry: RankingEntryDto }) {
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
            <CommercialBadge commercial={e.commercial} />
            {e.isDemo && <DemoBadge />}
          </div>
        </div>
      </div>
      <div className="entry__side">
        <ScoreRing score={entry.score} />
        <Link href={e.path} className="btn btn--ghost" style={{ minHeight: "2.25rem", padding: "0.4rem 1rem", fontSize: "0.85rem" }}>
          View profile
        </Link>
      </div>
    </li>
  );
}
