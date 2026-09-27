import Link from "next/link";
import type { DataQualityDto } from "@/types/api";
import { formatDate, humanize } from "@/lib/format";

/**
 * How well a profile is documented (Etap C). Deliberately shown apart from
 * the LexRank score and labelled: it describes the data, not the lawyer, and
 * it is not a ranking input.
 */
export function DataQualityPanel({ quality }: { quality: DataQualityDto | null | undefined }) {
  if (!quality) return null;
  const checked = formatDate(quality.calculatedAt);
  return (
    <section className="card dq" aria-labelledby="dq-heading">
      <p className="panel-title" id="dq-heading">
        Data quality
      </p>
      <p className="dq__score">
        <strong>{Math.round(quality.score)}%</strong>
        <span className="muted"> documented</span>
      </p>
      <ul className="dq__bars">
        {quality.dimensions.map((d) => (
          <li key={d.key} title={d.detail}>
            <span className="dq__label">
              {d.label} <span className="muted">({d.weight}%)</span>
            </span>
            <span className="dq__track" aria-hidden="true">
              <span className="dq__fill" style={{ width: `${Math.max(0, Math.min(100, d.score))}%` }} />
            </span>
            <span className="dq__value">{Math.round(d.score)}</span>
          </li>
        ))}
      </ul>
      {quality.missing.length > 0 && (
        <p className="muted dq__note">Not yet on record with a source: {quality.missing.map(humanize).join(", ")}.</p>
      )}
      <p className="muted dq__note">
        Measures how complete, fresh, well-sourced, verified and consistent this profile&apos;s data is — not how good the lawyer is. It is{" "}
        <strong>not part of the ranking</strong>. {checked ? `Checked ${checked}. ` : ""}
        <Link href="/methodology/#data-quality">How it works</Link>
      </p>
    </section>
  );
}
