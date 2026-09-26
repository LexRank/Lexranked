import Link from "next/link";
import type { EvidenceDto, FreshnessDto, VerificationBlock, VerificationState } from "@/types/api";
import { formatDate, formatShortDate, humanize, isoDate } from "@/lib/format";
import { METHODOLOGY_VERSION } from "@/lib/methodology";
import { ScoreRing, VerificationBadge } from "../ui";

/** Shared profile sections for lawyers and law firms. */

export function ScoreSection({ score, scoreVersion, calculatedAt }: { score: number | null; scoreVersion: string | null; calculatedAt: string | null }) {
  const calculated = formatDate(calculatedAt);
  return (
    <section className="card" aria-labelledby="score-heading">
      <h2 id="score-heading" style={{ fontSize: "1.4rem" }}>
        LexRank score
      </h2>
      <div style={{ display: "flex", gap: "1.5rem", alignItems: "center", flexWrap: "wrap" }}>
        <ScoreRing score={score} size="lg" />
        <div style={{ flex: 1, minWidth: "14rem" }}>
          {score === null ? (
            <p className="muted" style={{ margin: 0 }}>
              This profile has not been scored yet. A score is published only once enough verified data exists — it is never estimated.
            </p>
          ) : (
            <>
              <p style={{ margin: "0 0 0.5rem" }}>
                Scored with <strong>{scoreVersion === "demo" ? "demo placeholder values" : (scoreVersion ?? METHODOLOGY_VERSION)}</strong>
                {calculated && (
                  <>
                    {" "}
                    on <time dateTime={isoDate(calculatedAt)}>{calculated}</time>
                  </>
                )}
                .
              </p>
              <p className="muted" style={{ margin: 0, fontSize: "0.92rem" }}>
                A per-factor breakdown is shown only when each component can be explained from stored, sourced data. Payment never
                changes this score.
              </p>
            </>
          )}
          <p style={{ margin: "0.75rem 0 0", fontSize: "0.92rem" }}>
            <Link className="link-arrow" href="/methodology/">
              How the score is calculated
            </Link>
          </p>
        </div>
      </div>
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
  if (value === null || value === undefined) return "—";
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
