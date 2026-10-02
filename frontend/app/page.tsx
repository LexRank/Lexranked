import type { Metadata } from "next";
import Link from "next/link";
import { ArticleCard, RankingCard } from "@/components/cards";
import { CheckIcon, MapPinIcon, XCircleIcon } from "@/components/icons";
import { CollectArt, HeroArt, RankArt, VerifyArt } from "@/components/home/Illustrations";
import { PracticeIcon } from "@/components/home/PracticeIcon";
import { JsonLd } from "@/components/JsonLd";
import { RankingFinder } from "@/components/RankingFinder";
import { DemoNotice } from "@/components/ui";
import { finderOptions } from "@/lib/content/rankings";
import { SITE_NAME } from "@/lib/config/site";
import { allRankings, load } from "@/lib/data/loaders";
import { formatCount, pluralize } from "@/lib/format";
import { componentsWithWeights, METHODOLOGY_PRINCIPLES } from "@/lib/methodology";
import { buildMetadata } from "@/lib/seo/metadata";
import { organizationJsonLd, websiteJsonLd } from "@/lib/seo/jsonld";
import { getArticles, getPracticeAreas, getScoreVersions, getStates } from "@/lib/wordpress/api";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: `${SITE_NAME} — Top-rated lawyers, ranked by data`,
  absoluteTitle: true,
  description:
    "Find top-rated lawyers and law firms in the United States. Transparent, source-backed rankings with verified credentials — payment never changes a ranking.",
  path: "/",
});

/** What never changes a score or a position (enforced by the engine, see the methodology). */
const NEVER_RANKED = [
  "Payment, advertising or sponsorship",
  "Claiming or upgrading a profile",
  "AI-written text",
  "Unsourced or conflicting claims",
];

const STEPS = [
  {
    Art: CollectArt,
    title: "We collect the facts",
    body: "Licenses, practice areas, experience, awards and client reviews come from official registries, firm websites and other documented sources. Each fact keeps its source and date.",
  },
  {
    Art: VerifyArt,
    title: "We verify them",
    body: "A profile is marked verified only when every required check has passed. Conflicting or unsourced claims are flagged, not guessed.",
  },
  {
    Art: RankArt,
    title: "We rank with a public formula",
    body: "Scores are calculated deterministically from the stored facts with a published methodology, so the same data always produces the same ranking.",
  },
];

export default async function HomePage() {
  const [rankings, states, practiceAreas, articles, versions] = await Promise.all([
    load(allRankings),
    load(async () => (await getStates()).data),
    load(async () => (await getPracticeAreas()).data),
    load(async () => (await getArticles({ per_page: 6 })).data),
    load(async () => (await getScoreVersions()).data),
  ]);
  // Thin posts (e.g. a CMS default post) are not promoted.
  const guides = articles.ok ? articles.data.filter((a) => !a.isThin).slice(0, 3) : [];

  const published = rankings.ok ? rankings.data.filter((r) => !r.isThin) : [];
  const lawyersCovered = published.reduce((sum, r) => sum + r.entryCount, 0);
  const hasDemo = published.some((r) => r.isDemo);
  const areas = practiceAreas.ok ? practiceAreas.data.filter((p) => p.eligibility?.exists !== false) : [];
  const active = versions.ok ? versions.data.versions.find((v) => v.id === versions.data.active) : undefined;
  const components = componentsWithWeights(active?.weights ?? null);
  const totalWeight = components.reduce((sum, c) => sum + c.weight, 0) || 1;

  return (
    <>
      <JsonLd data={[organizationJsonLd(), websiteJsonLd()]} />

      <section className="hero hero--home">
        <div className="container hero__grid">
          <div className="hero__copy">
            <p className="eyebrow">Independent lawyer rankings · United States</p>
            <h1>
              Find the right lawyer, <em>ranked by data</em> — not by ads.
            </h1>
            <p className="lead">
              LexRanked compares lawyers and law firms on verified facts: licenses, experience, practice focus and client reviews.
              Every fact is traceable to a source, the scoring formula is public, and payment never changes a ranking.
            </p>
            <RankingFinder options={finderOptions(published)} variant="bar" />
            <ul className="hero__checks">
              {["Published methodology", "Sources for every key fact", "No paid positions"].map((t) => (
                <li key={t}>
                  <CheckIcon />
                  {t}
                </li>
              ))}
            </ul>
          </div>
          <div className="hero__art">
            <HeroArt className="hero-art" />
          </div>
        </div>
      </section>

      <div className="container">
        <dl className="trust-strip">
          <div>
            <dt>Published {published.length === 1 ? "ranking" : "rankings"}</dt>
            <dd>{formatCount(published.length) ?? "—"}</dd>
          </div>
          <div>
            <dt>Ranked profiles</dt>
            <dd>{formatCount(lawyersCovered) ?? "—"}</dd>
          </div>
          <div>
            <dt>Transparent scoring factors</dt>
            <dd>{components.length}</dd>
          </div>
          <div>
            <dt>Positions for sale</dt>
            <dd>0</dd>
          </div>
        </dl>
      </div>

      {hasDemo && (
        <div className="container" style={{ marginTop: "1.5rem" }}>
          <DemoNotice />
        </div>
      )}

      <section className="section">
        <div className="container">
          <div className="section__head section__head--center">
            <div>
              <p className="eyebrow">How LexRanked works</p>
              <h2>From public records to a ranking you can check</h2>
            </div>
          </div>
          <ol className="how-steps">
            {STEPS.map(({ Art, title, body }, i) => (
              <li key={title} className="how-step">
                <div className="how-step__art">
                  <Art />
                </div>
                <p className="how-step__num">Step {i + 1}</p>
                <h3>{title}</h3>
                <p className="muted">{body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {areas.length > 0 && (
        <section className="section section--white">
          <div className="container">
            <div className="section__head">
              <div>
                <p className="eyebrow">Legal issues</p>
                <h2>Find a lawyer by legal issue</h2>
              </div>
              <Link className="link-arrow" href="/practice-areas/">
                All practice areas
              </Link>
            </div>
            <ul className="issue-grid">
              {areas.slice(0, 12).map((p) => (
                <li key={p.id}>
                  <Link className="issue" href={p.path}>
                    <span className="issue__icon">
                      <PracticeIcon slug={p.slug} />
                    </span>
                    <span>
                      <strong>{p.name}</strong>
                      <small>
                        {pluralize(p.lawyerCount, "lawyer")}
                        {p.lawFirmCount > 0 ? ` · ${pluralize(p.lawFirmCount, "firm")}` : ""}
                      </small>
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </section>
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

      <section className="section section--navy">
        <div className="container decides">
          <div>
            <p className="eyebrow">Why you can trust a position</p>
            <h2>What decides a ranking — and what never does</h2>
            <p className="lead">
              A position is the result of {components.length} weighted factors calculated from sourced facts. The ranking code cannot
              read commercial data at all.
            </p>
            <Link className="btn btn--primary" href="/methodology/">
              Read the full methodology
            </Link>
          </div>
          <div className="decides__panel">
            <p className="panel-title">Decides the score</p>
            <div className="mix" role="img" aria-label={components.map((c) => `${c.label} ${c.weight}%`).join(", ")}>
              {components.map((c) => (
                <span key={c.key} style={{ flexGrow: c.weight / totalWeight }} />
              ))}
            </div>
            <ul className="mix__legend">
              {components.map((c) => (
                <li key={c.key}>
                  <span className="mix__dot" />
                  {c.label}
                  <strong>{c.weight}%</strong>
                </li>
              ))}
            </ul>
            <p className="panel-title" style={{ marginTop: "1.5rem" }}>
              Never affects a position
            </p>
            <ul className="never">
              {NEVER_RANKED.map((t) => (
                <li key={t}>
                  <XCircleIcon />
                  {t}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      <section className="section section--white">
        <div className="container">
          <div className="section__head">
            <div>
              <p className="eyebrow">Our principles</p>
              <h2>Rankings you can check for yourself</h2>
            </div>
          </div>
          <div className="grid grid--4">
            {METHODOLOGY_PRINCIPLES.map((p) => (
              <div key={p.title} className="principle">
                <h3>{p.title}</h3>
                <p className="muted">{p.body}</p>
              </div>
            ))}
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
        <div className="container">
          <div className="stack">
            {states.ok && states.data.length > 0 && (
              <div>
                <p className="eyebrow">Browse by state</p>
                <ul className="chips">
                  {states.data.map((s) => (
                    <li key={s.id}>
                      <Link className="chip chip--icon" href={s.path}>
                        <MapPinIcon />
                        {s.name}
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
        </div>
      </section>
    </>
  );
}
