import type { Metadata } from "next";
import Link from "next/link";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { componentsWithWeights, METHODOLOGY_PRINCIPLES } from "@/lib/methodology";
import { load } from "@/lib/data/loaders";
import { formatDate, humanize, isoDate } from "@/lib/format";
import { getDataQualityModel, getMethodology, getPageEligibilityModel, getScoreVersions } from "@/lib/wordpress/api";
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

export const revalidate = 3600;

const FRESHNESS_LABEL: Record<string, string> = {
  bar_status: "Bar status and license",
  review_data: "Ratings and review counts",
  website: "Website and contact details",
  profile: "Other profile facts",
};

const PAGE_LABEL: Record<string, string> = {
  hub: "State, city, practice area",
  ranking: "Ranking",
  profile: "Lawyer or firm profile",
  article: "Guide",
  comparison: "Comparison",
  listing: "Listing",
};

export default async function MethodologyPage() {
  const [versions, quality, pages, live] = await Promise.all([
    load(async () => (await getScoreVersions()).data),
    load(() => getDataQualityModel()),
    load(() => getPageEligibilityModel()),
    load(() => getMethodology()),
  ]);
  const updated = live.ok ? formatDate(live.data.updatedAt) : null;
  const active = versions.ok ? versions.data.versions.find((v) => v.id === versions.data.active) : undefined;
  const components = componentsWithWeights(active?.weights ?? null);
  const params = active?.params;
  const versionLabel = `LexRank ${active?.id ?? "v1.0"}`;
  return (
    <>
      <JsonLd data={collectionPageJsonLd("LexRanked ranking methodology", "/methodology/", "How LexRanked calculates lawyer rankings.")} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Methodology", path: "/methodology/" },
        ]}
        eyebrow={versionLabel}
        title="How we rank lawyers"
        lead="LexRank is a deterministic scoring methodology. The same data and methodology version always produce the same score — and no one can pay to change it."
      >
        {live.ok && (
          <div className="page-header__meta">
            <span>
              Methodology <strong>{versionLabel}</strong>
            </span>
            {updated && (
              <span>
                Scores updated{" "}
                <strong>
                  <time dateTime={isoDate(live.data.updatedAt)}>{updated}</time>
                </strong>
              </span>
            )}
            <span>
              Data quality <strong>{live.data.dataQuality}</strong> · Pages <strong>{live.data.pageEligibility}</strong>
            </span>
          </div>
        )}
      </PageHeader>
      <div className="container section layout-sidebar">
        <article className="stack prose" style={{ maxWidth: "none", gap: "2.5rem" }}>
          <section>
            <h2>The seven factors</h2>
            <p>Each lawyer or firm receives a LexRank score from 0 to 100, built from seven weighted components:</p>
            <div className="grid grid--2">
              {components.map((c) => (
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
            {active?.input === "facts" && (
              <p>
                <strong>Evidence only.</strong> Since {versionLabel}, the engine reads each value from the evidence layer: sources →
                claims → verified or sourced facts. A value on a profile without a source, or one where equally authoritative sources
                disagree, counts as missing until it is resolved. Earlier rankings keep the version that produced them.
              </p>
            )}
            <p>
              <strong>Every position is explained.</strong> Each ranking entry has a &ldquo;Why #N?&rdquo; panel built from its score
              components — its strongest and weakest factors against the ranking average and what separates it from the entry above
              — and, after a recalculation, what changed: its own data, its components, competitors that moved past it, or a new
              methodology version. These explanations are computed from stored snapshots, never written by AI.
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
              r = average rating, n = number of reviews, m = baseline rating
              {params ? ` (${params.review_prior_mean})` : ""}, C = confidence constant{params ? ` (${params.review_prior_weight} reviews)` : ""}.
              {params ? ` Adjusted ratings of ${params.review_floor} or lower earn no review-strength points; 5.0 earns full points.` : ""} We
              never copy review text or publish invented quotations.
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

          <section id="data-quality">
            <h2>Data quality (not a ranking)</h2>
            <p>
              Every profile also shows a <strong>Data Quality</strong> percentage. It measures how well the profile is documented —
              not how good the lawyer is — and it is <strong>not an input to the LexRank score</strong>. It is recalculated whenever
              the evidence changes and daily, because data ages.
            </p>
            {quality.ok && (
              <>
                <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col">Dimension</th>
                      <th scope="col">Weight</th>
                      <th scope="col">What it measures</th>
                    </tr>
                  </thead>
                  <tbody>
                    {quality.data.dimensions.map((d) => (
                      <tr key={d.key}>
                        <td>{d.label}</td>
                        <td>{d.weight}%</td>
                        <td>{d.description}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                </div>
                <p className="muted" style={{ fontSize: "0.85rem" }}>
                  Model {quality.data.version}.
                  {quality.data.summary.average !== null &&
                    ` Across ${quality.data.summary.count} published profiles the average is ${quality.data.summary.average}%.`}{" "}
                  The ranking has its own, separately published “data quality” factor above; the two are not combined.
                </p>
              </>
            )}
          </section>

          {live.ok && (
            <section id="data-sources">
              <h2>Data sources</h2>
              <p>
                Every fact comes from a source, and sources are ranked by tier. When sources disagree, the higher tier wins; when
                sources of the same tier disagree, the fact is marked as conflicting and not used until it is resolved.
              </p>
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col">Tier</th>
                      <th scope="col">Meaning</th>
                      <th scope="col">Source types</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[...new Set(live.data.sourceTiers.map((t) => t.tier))].map((tier) => {
                      const types = live.data.sourceTiers.filter((t) => t.tier === tier);
                      return (
                        <tr key={tier}>
                          <td>{tier}</td>
                          <td>{types[0]?.tierLabel}</td>
                          <td>{types.map((t) => humanize(t.type)).join(", ")}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              <h2 id="update-frequency">How often data is updated</h2>
              <ul>
                <li>
                  <strong>Scores:</strong> {live.data.schedule.recalculation}
                </li>
                <li>
                  <strong>Data quality:</strong> {live.data.schedule.dataQuality}
                </li>
                <li>
                  <strong>History:</strong> {live.data.schedule.snapshots}
                </li>
              </ul>
              <p>Each kind of fact has its own freshness window; older facts are flagged for re-checking and count as stale:</p>
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col">Data</th>
                      <th scope="col">Re-check after</th>
                    </tr>
                  </thead>
                  <tbody>
                    {live.data.freshness.map((f) => (
                      <tr key={f.category}>
                        <td>{FRESHNESS_LABEL[f.category] ?? humanize(f.category)}</td>
                        <td>{f.maxAgeDays} days</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          <section id="page-eligibility">
            <h2>When a page exists</h2>
            <p>
              LexRanked does not create a page because a search term exists. A ranking, city, state or practice-area page exists only
              when the database holds enough published profiles for it, and it is indexed by search engines only when that data is
              real, verified and backed by sources. Below the threshold the page is not published, or is published but kept out of
              search. The same rules decide the sitemap.
            </p>
            {pages.ok && (
              <>
                <div className="table-wrap">
                  <table className="table">
                    <thead>
                      <tr>
                        <th scope="col">Page</th>
                        <th scope="col">Requirement</th>
                        <th scope="col">Minimum</th>
                        <th scope="col">Needed to</th>
                      </tr>
                    </thead>
                    <tbody>
                      {Object.entries(pages.data.types).flatMap(([type, rules]) =>
                        rules.map((r, i) => (
                          <tr key={`${type}-${r.key}`}>
                            <td>{i === 0 ? PAGE_LABEL[type] ?? type : ""}</td>
                            <td>
                              {r.label}
                              <span className="muted" style={{ display: "block", fontSize: "0.82rem" }}>
                                {r.description}
                              </span>
                            </td>
                            <td>{r.key === "coverage" ? `${Math.round(r.required * 100)}%` : r.key === "real" && r.required === 1 ? "Yes" : r.required}</td>
                            <td>{r.level === "exist" ? "Exist" : "Be indexed"}</td>
                          </tr>
                        )),
                      )}
                    </tbody>
                  </table>
                </div>
                <p className="muted" style={{ fontSize: "0.85rem" }}>Rules {pages.data.version}.</p>
              </>
            )}
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
