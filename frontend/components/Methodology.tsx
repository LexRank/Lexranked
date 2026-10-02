import Link from "next/link";
import { METHODOLOGY_COMPONENTS, METHODOLOGY_VERSION } from "@/lib/methodology";

/** "How we calculate rankings" panel (spec §24). */
export function MethodologyPanel({ compact = false }: { compact?: boolean }) {
  return (
    <section className="card" aria-labelledby="how-we-rank">
      <p className="panel-title" id="how-we-rank">
        How we calculate rankings
      </p>
      {!compact && (
        <p className="muted" style={{ fontSize: "0.92rem" }}>
          {METHODOLOGY_VERSION} evaluates publicly available and verified information. Weights:
        </p>
      )}
      <ul className="weights">
        {METHODOLOGY_COMPONENTS.map((c) => (
          <li key={c.key}>
            <span>{c.label}</span>
            <strong>{c.weight}%</strong>
            <span className="weights__bar" aria-hidden="true">
              <span style={{ width: `${(c.weight / 30) * 100}%` }} />
            </span>
          </li>
        ))}
      </ul>
      <p style={{ margin: "1.25rem 0 0", fontSize: "0.9rem" }}>
        <Link className="link-arrow" href="/methodology/">
          Full methodology
        </Link>
      </p>
    </section>
  );
}
