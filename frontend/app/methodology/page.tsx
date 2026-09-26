import type { Metadata } from "next";
import Link from "next/link";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { METHODOLOGY_COMPONENTS, METHODOLOGY_PRINCIPLES, METHODOLOGY_VERSION } from "@/lib/methodology";
import { collectionPageJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const metadata: Metadata = buildMetadata({
  title: "Ranking Methodology",
  description:
    "How LexRanked ranks lawyers: seven weighted factors, volume-adjusted review scores, source tiers, verification and strict separation of payment from rankings.",
  path: "/methodology/",
  type: "article",
});

const TIERS = [
  ["1", "Official government, court, bar or regulatory sources"],
  ["2", "The lawyer's or firm's own official website"],
  ["3", "Reputable professional directories"],
  ["4", "Review platforms"],
  ["5", "Secondary sources"],
];

export default function MethodologyPage() {
  return (
    <>
      <JsonLd data={collectionPageJsonLd("LexRanked ranking methodology", "/methodology/", "How LexRanked calculates lawyer rankings.")} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Methodology", path: "/methodology/" },
        ]}
        eyebrow={METHODOLOGY_VERSION}
        title="How we rank lawyers"
        lead="LexRank is a deterministic scoring methodology. The same data and methodology version always produce the same score — and no one can pay to change it."
      />
      <div className="container section layout-sidebar">
        <article className="stack prose" style={{ maxWidth: "none", gap: "2.5rem" }}>
          <section>
            <h2>The seven factors</h2>
            <p>Each lawyer or firm receives a LexRank score from 0 to 100, built from seven weighted components:</p>
            <div className="grid grid--2">
              {METHODOLOGY_COMPONENTS.map((c) => (
                <div key={c.key} className="card">
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", gap: "1rem" }}>
                    <h3 style={{ fontSize: "1.1rem", margin: 0 }}>{c.label}</h3>
                    <strong style={{ color: "var(--brass-600)", fontSize: "1.25rem" }}>{c.weight}%</strong>
                  </div>
                  <p className="muted" style={{ margin: "0.5rem 0 0", fontSize: "0.92rem" }}>
                    {c.description}
                  </p>
                </div>
              ))}
            </div>
            <p className="muted" style={{ fontSize: "0.9rem", marginTop: "1rem" }}>
              Weights belong to a versioned configuration. Changing them creates a new methodology version instead of silently
              altering published scores.
            </p>
          </section>

          <section>
            <h2>Why star ratings alone are not enough</h2>
            <p>
              A 5.0 average from three reviews says much less than a 4.8 average from four hundred. LexRank uses a Bayesian average
              that pulls ratings with few reviews toward a neutral baseline, in proportion to how little evidence supports them:
            </p>
            <pre className="card" style={{ overflowX: "auto", fontSize: "0.95rem" }}>
              <code>adjusted rating = (C × m + n × r) / (C + n)</code>
            </pre>
            <p className="muted" style={{ fontSize: "0.92rem" }}>
              r = average rating, n = number of reviews, m = baseline rating, C = confidence constant. We never copy review text or
              publish invented quotations.
            </p>
          </section>

          <section>
            <h2>Sources and evidence</h2>
            <p>
              Every important fact — bar status, years of experience, ratings, practice areas — is stored with the source it came
              from, when it was retrieved and how confident we are in it. When sources disagree, higher tiers take precedence:
            </p>
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Tier</th>
                    <th scope="col">Source type</th>
                  </tr>
                </thead>
                <tbody>
                  {TIERS.map(([tier, label]) => (
                    <tr key={tier}>
                      <td>
                        <span className={`tier tier--${tier}`}>{tier}</span>
                      </td>
                      <td>{label}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="muted" style={{ fontSize: "0.92rem", marginTop: "1rem" }}>
              If no reliable source exists, the field stays empty. A missing input scores zero for its component and lowers the
              data-quality component — it is never estimated.
            </p>
          </section>

          <section>
            <h2>Verification and freshness</h2>
            <p>
              A profile is marked <strong>verified</strong> only when every required check — such as identity, license and bar status
              for lawyers — has passed and none has expired. Each data point has a freshness target (for example 30 days for bar
              status and 7 days for review data), and profiles show when their data was last verified.{" "}
              <Link href="/verified/">Read more about verification</Link>.
            </p>
          </section>

          <section>
            <h2>Payment independence</h2>
            <p>
              Lawyers and firms may claim profiles or purchase featured placements in the future. These are stored separately from the
              organic score, are always labelled as paid, and are never an input to the scoring engine.
            </p>
          </section>

          <section>
            <h2>What a ranking is — and isn&apos;t</h2>
            <p>
              Rankings summarize publicly available and verified information to help you build a shortlist. They are not legal advice,
              an endorsement or a guarantee of outcome. LexRanked is not a law firm and not a lawyer referral service.
            </p>
          </section>
        </article>
        <aside className="stack">
          {METHODOLOGY_PRINCIPLES.map((p) => (
            <div key={p.title} className="card">
              <h3 style={{ fontSize: "1.05rem" }}>{p.title}</h3>
              <p className="muted" style={{ margin: 0, fontSize: "0.9rem" }}>
                {p.body}
              </p>
            </div>
          ))}
        </aside>
      </div>
    </>
  );
}
