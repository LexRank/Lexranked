import Link from "next/link";
import type { ComparableType, EvidenceDto, FreshnessDto, RankingPosition, ScoreComponent, VerificationBlock, VerificationState } from "@/types/api";
import { formatDate, formatShortDate, humanize, isoDate } from "@/lib/format";
import { METHODOLOGY_VERSION } from "@/lib/methodology";
import { compareHref } from "@/lib/content/compare";
import { ScoreRing, VerificationBadge } from "../ui";

/** Shared profile sections for lawyers and law firms. */

export function ScoreSection({
  score,
  scoreVersion,
  calculatedAt,
  breakdown = [],
}: {
  score: number | null;
  scoreVersion: string | null;
  calculatedAt: string | null;
  breakdown?: ScoreComponent[];
}) {
  const calculated = formatDate(calculatedAt);
  return (
    <section id="score" className="card" aria-labelledby="score-heading">
      <h2 id="score-heading" style={{ fontSize: "1.4rem" }}>
        LexRank score
      </h2>
      <div style={{ display: "flex", gap: "1.5rem", alignItems: "center", flexWrap: "wrap" }}>
        <ScoreRing score={score} size="lg" />
        <div style={{ flex: 1, minWidth: "14rem" }}>
          {score === null ? (
            <p className="muted" style={{ margin: 0 }}>
              This profile has not been scored yet. A score is published only once enough verified data exists - it is never estimated.
            </p>
          ) : (
            <p style={{ margin: 0 }}>
              Calculated with <strong>{scoreVersion ? `LexRank ${scoreVersion}` : METHODOLOGY_VERSION}</strong>
              {calculated && (
                <>
                  {" "}
                  on <time dateTime={isoDate(calculatedAt)}>{calculated}</time>
                </>
              )}
              . The same data always produces the same score, and payment never changes it.
            </p>
          )}
          <p style={{ margin: "0.75rem 0 0", fontSize: "0.92rem" }}>
            <Link className="link-arrow" href="/methodology/">
              How the score is calculated
            </Link>
          </p>
        </div>
      </div>
      {breakdown.length > 0 && <ScoreBreakdown components={breakdown} />}
    </section>
  );
}

/** Per-factor breakdown (spec §25): only components explained by stored data. */
export function ScoreBreakdown({ components }: { components: ScoreComponent[] }) {
  return (
    <div style={{ marginTop: "1.5rem", paddingTop: "1.25rem", borderTop: "1px solid var(--line)" }}>
      <p className="panel-title">Score breakdown</p>
      <ul className="breakdown">
        {components.map((c) => (
          <li key={c.key}>
            <div className="breakdown__row">
              <span className="breakdown__label">{c.label}</span>
              <span className="breakdown__points">
                <strong>{c.points.toFixed(1)}</strong> / {c.max}
              </span>
            </div>
            <span className="weights__bar" aria-hidden="true">
              <span style={{ width: `${c.max > 0 ? (c.points / c.max) * 100 : 0}%` }} />
            </span>
            <p className="breakdown__why">
              {c.explanation}
              {c.missing.length > 0 && <> Not on record: {c.missing.map(humanize).join(", ").toLowerCase()} (scored 0, never estimated).</>}
            </p>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function RankingPositions({ rankings, self }: { rankings: RankingPosition[]; self?: { type: ComparableType; entityId: number | null | undefined } }) {
  const shown = rankings.filter((r) => r.path);
  if (shown.length === 0) return null;
  return (
    <section className="card" aria-labelledby="rankings-heading">
      <h2 id="rankings-heading" style={{ fontSize: "1.4rem" }}>
        Rankings
      </h2>
      <ul className="weights" style={{ gap: "0.5rem" }}>
        {shown.map((r) => {
          const compare = self ? (r.neighbors ?? []).map((n) => ({ n, href: compareHref(self.type, [self.entityId, n.entityId]) })).filter((c) => c.href) : [];
          return (
            <li key={r.id} style={{ paddingBottom: "0.5rem", borderBottom: "1px solid var(--line)" }}>
              <div style={{ display: "flex", justifyContent: "space-between", gap: "1rem" }}>
                <Link href={r.path as string}>{r.title}</Link>
                <strong>#{r.position}</strong>
              </div>
              {compare.length > 0 && (
                <ul className="compare-links" aria-label={`Compare within ${r.title}`}>
                  {compare.map(({ n, href }) => (
                    <li key={n.entityId}>
                      <Link href={href as string}>
                        {`Compare with #${n.position} ${n.name}`}
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}

const CHECK_LABEL: Record<string, string> = {
  identity: "Identity",
  business: "Business registration",
  location: "Location",
  website: "Website",
  license: "License",
  bar_status: "Bar status",
  practice_area: "Practice area",
  review_data: "Review data",
};

export function VerificationSection({ verification, freshness }: { verification: VerificationBlock; freshness: FreshnessDto }) {
  const checks = Object.entries(verification.checks);
  const verifiedAt = formatDate(verification.verifiedAt);
  return (
    <section className="card" aria-labelledby="verification-heading">
      <h2 id="verification-heading" style={{ fontSize: "1.4rem" }}>
        Verification
      </h2>
      <p style={{ display: "flex", gap: "0.75rem", alignItems: "center", flexWrap: "wrap" }}>
        <VerificationBadge status={verification.status} />
        {verifiedAt && (
          <span className="muted" style={{ fontSize: "0.92rem" }}>
            Data verified <time dateTime={isoDate(verification.verifiedAt)}>{verifiedAt}</time>
          </span>
        )}
      </p>
      {checks.length > 0 ? (
        <ul className="weights" style={{ gap: "0.5rem" }}>
          {checks.map(([type, status]) => (
            <li key={type} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", paddingBottom: "0.5rem", borderBottom: "1px solid var(--line)" }}>
              <span>{CHECK_LABEL[type] ?? humanize(type)}</span>
              <VerificationBadge status={status as VerificationState} />
            </li>
          ))}
        </ul>
      ) : (
        <p className="muted" style={{ margin: 0 }}>
          No verification checks have been completed for this profile yet.
        </p>
      )}
      {freshness.isStale && verification.status === "verified" && (
        <p className="muted" style={{ margin: "1rem 0 0", fontSize: "0.9rem" }}>
          Some data is older than our {freshness.maxAgeDays}-day freshness target and is scheduled for re-verification.
        </p>
      )}
      <p style={{ margin: "1rem 0 0", fontSize: "0.9rem" }}>
        <Link className="link-arrow" href="/verified/">
          What verification means
        </Link>
      </p>
    </section>
  );
}

function displayValue(value: unknown): string {
  if (value === null || value === undefined) return "-";
  if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") return String(value);
  return JSON.stringify(value);
}

export function SourcesSection({ sources }: { sources: EvidenceDto[] }) {
  return (
    <section aria-labelledby="sources-heading">
      <h2 id="sources-heading" style={{ fontSize: "1.4rem" }}>
        Sources
      </h2>
      {sources.length === 0 ? (
        <p className="muted">No sourced facts have been recorded for this profile yet. Facts without a source are not published.</p>
      ) : (
        <>
          <p className="muted" style={{ fontSize: "0.92rem" }}>
            Each fact below is linked to where it came from. Tier 1 sources (official registries) take precedence over lower tiers.
          </p>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">Fact</th>
                  <th scope="col">Value</th>
                  <th scope="col" className="col-source">Source</th>
                  <th scope="col">Tier</th>
                  <th scope="col">Retrieved</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                {sources.map((s, i) => (
                  <tr key={`${s.field}-${i}`}>
                    <td className="nowrap">{humanize(s.field)}</td>
                    <td style={{ overflowWrap: "anywhere" }}>{displayValue(s.value)}</td>
                    <td>
                      {s.source.url ? (
                        <a href={s.source.url} rel="nofollow noopener noreferrer" target="_blank">
                          {s.source.name ?? humanize(s.source.type)}
                        </a>
                      ) : (
                        (s.source.name ?? humanize(s.source.type))
                      )}
                    </td>
                    <td>
                      <span className={`tier tier--${s.source.tier}`} title={`Tier ${s.source.tier}`}>
                        {s.source.tier}
                      </span>
                    </td>
                    <td className="nowrap">
                      <time dateTime={isoDate(s.retrievedAt)}>{formatShortDate(s.retrievedAt)}</time>
                    </td>
                    <td>{humanize(s.verificationStatus)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}
