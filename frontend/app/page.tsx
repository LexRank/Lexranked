import type { Metadata } from "next";
import Link from "next/link";
import { ArticleCard, RankingCard } from "@/components/cards";
import { DocumentIcon, ScaleIcon, ShieldCheckIcon, ClockIcon } from "@/components/icons";
import { JsonLd } from "@/components/JsonLd";
import { MethodologyPanel } from "@/components/Methodology";
import { RankingFinder } from "@/components/RankingFinder";
import { DemoNotice } from "@/components/ui";
import { finderOptions } from "@/lib/content/rankings";
import { SITE_NAME } from "@/lib/config/site";
import { allRankings, load } from "@/lib/data/loaders";
import { formatCount } from "@/lib/format";
import { METHODOLOGY_PRINCIPLES } from "@/lib/methodology";
import { buildMetadata } from "@/lib/seo/metadata";
import { organizationJsonLd, websiteJsonLd } from "@/lib/seo/jsonld";
import { getArticles, getPracticeAreas, getStates } from "@/lib/wordpress/api";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: `${SITE_NAME} — Top-rated lawyers, ranked by data`,
  absoluteTitle: true,
  description:
    "Find top-rated lawyers and law firms in the United States. Transparent, source-backed rankings with verified credentials — payment never changes a ranking.",
  path: "/",
});

const PRINCIPLE_ICONS = [ScaleIcon, DocumentIcon, ClockIcon, ShieldCheckIcon];

export default async function HomePage() {
  const [rankings, states, practiceAreas, articles] = await Promise.all([
    load(allRankings),
    load(async () => (await getStates()).data),
    load(async () => (await getPracticeAreas()).data),
    load(async () => (await getArticles({ per_page: 6 })).data),
  ]);
  // Thin posts (e.g. a CMS default post) are not promoted.
  const guides = articles.ok ? articles.data.filter((a) => !a.isThin).slice(0, 3) : [];

  const published = rankings.ok ? rankings.data.filter((r) => !r.isThin) : [];
  const lawyersCovered = published.reduce((sum, r) => sum + r.entryCount, 0);
  const hasDemo = published.some((r) => r.isDemo);

  return (
    <>
      <JsonLd data={[organizationJsonLd(), websiteJsonLd()]} />

      <section className="hero">
        <div className="container hero__grid">
          <div>
            <p className="eyebrow">Independent · Data-driven · United States</p>
            <h1>
              Find top-rated lawyers, <em>ranked by data</em> — not by ads.
            </h1>
            <p className="lead">
              LexRanked scores lawyers and law firms with a published, reproducible methodology. Every important fact is traceable to
              a source, and payment never changes a ranking.
            </p>
            <div className="hero__stats">
              <div>
                <strong>{formatCount(published.length) ?? "—"}</strong>
                published {published.length === 1 ? "ranking" : "rankings"}
              </div>
              <div>
                <strong>{formatCount(lawyersCovered) ?? "—"}</strong>
                ranked profiles
              </div>
              <div>
                <strong>7</strong>
                transparent scoring factors
              </div>
            </div>
          </div>
          <RankingFinder options={finderOptions(published)} />
        </div>
      </section>

      {hasDemo && (
        <div className="container" style={{ marginTop: "1.5rem" }}>
          <DemoNotice />
        </div>
      )}

      {published.length > 0 && (
        <section className="section">
          <div className="container">
            <div className="section__head">
              <div>
                <p className="eyebrow">Rankings</p>
                <h2>Current rankings</h2>
              </div>
              <Link className="link-arrow" href="/rankings/">
                All rankings
              </Link>
            </div>
            <div className="grid grid--3">
              {published.slice(0, 6).map((r) => (
                <RankingCard key={r.id} ranking={r} />
              ))}
            </div>
          </div>
        </section>
      )}

      <section className="section section--white">
        <div className="container">
          <div className="section__head">
            <div>
              <p className="eyebrow">Why LexRanked</p>
              <h2>Rankings you can check for yourself</h2>
            </div>
            <Link className="link-arrow" href="/methodology/">
              Read the methodology
            </Link>
          </div>
          <div className="grid grid--4">
            {METHODOLOGY_PRINCIPLES.map((p, i) => {
              const Icon = PRINCIPLE_ICONS[i] ?? ScaleIcon;
              return (
                <div key={p.title} className="card">
                  <Icon className="principle-icon" />
                  <h3 style={{ fontSize: "1.1rem", marginTop: "0.75rem" }}>{p.title}</h3>
                  <p className="muted" style={{ fontSize: "0.92rem", margin: 0 }}>
                    {p.body}
                  </p>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {guides.length > 0 && (
        <section className="section">
          <div className="container">
            <div className="section__head">
              <div>
                <p className="eyebrow">Guides</p>
                <h2>Before you hire a lawyer</h2>
              </div>
              <Link className="link-arrow" href="/articles/">
                All guides
              </Link>
            </div>
            <div className="grid grid--3">
              {guides.map((a) => (
                <ArticleCard key={a.id} article={a} />
              ))}
            </div>
          </div>
        </section>
      )}

      <section className="section">
        <div className="container layout-sidebar">
          <div className="stack">
            {states.ok && states.data.length > 0 && (
              <div>
                <p className="eyebrow">Browse by state</p>
                <ul className="chips">
                  {states.data.map((s) => (
                    <li key={s.id}>
                      <Link className="chip" href={s.path}>
                        {s.name}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {practiceAreas.ok && practiceAreas.data.length > 0 && (
              <div>
                <p className="eyebrow">Browse by practice area</p>
                <ul className="chips">
                  {practiceAreas.data.map((p) => (
                    <li key={p.id}>
                      <Link className="chip chip--brass" href={p.path}>
                        {p.name}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="card">
              <h2 style={{ fontSize: "1.5rem" }}>A ranking is a starting point, not a verdict</h2>
              <p className="muted" style={{ margin: 0 }}>
                Our scores summarize publicly available and verified information. Always speak with a lawyer directly about your
                situation before hiring. LexRanked does not provide legal advice and is not a lawyer referral service.
              </p>
            </div>
          </div>
          <aside>
            <MethodologyPanel />
          </aside>
        </div>
      </section>
    </>
  );
}
