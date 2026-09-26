import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { cache } from "react";
import { RankingCard, RankingEntry } from "@/components/cards";
import { JsonLd } from "@/components/JsonLd";
import { MethodologyPanel } from "@/components/Methodology";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice } from "@/components/ui";
import { rankingEligibility } from "@/lib/content/eligibility";
import { resolveRanking } from "@/lib/content/rankings";
import { allRankings } from "@/lib/data/loaders";
import { formatDate, isoDate, pluralize } from "@/lib/format";
import { METHODOLOGY_VERSION } from "@/lib/methodology";
import { collectionPageJsonLd, rankingJsonLd, type Crumb } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import { getRanking } from "@/lib/wordpress/api";

export const revalidate = 300;

export function generateStaticParams() {
  return []; // Rendered on first request, then cached (ISR).
}

/** One ranking lookup per request, shared by generateMetadata and the page. */
const loadRanking = cache(async (segments: string[]) => {
  const resolution = resolveRanking(segments, await allRankings());
  if (resolution.kind !== "match") return resolution;
  const ranking = await getRanking(String(resolution.ranking.id));
  return ranking ? { kind: "found" as const, ranking } : { kind: "none" as const };
});

function crumbsFor(title: string, path: string, location: { state: string | null; stateSlug: string | null; city: string | null; citySlug: string | null } | null): Crumb[] {
  const crumbs: Crumb[] = [
    { name: "Home", path: "/" },
    { name: "Rankings", path: "/rankings/" },
  ];
  if (location?.state && location.stateSlug) crumbs.push({ name: location.state, path: `/states/${location.stateSlug}/` });
  if (location?.city && location.citySlug) crumbs.push({ name: location.city, path: `/cities/${location.citySlug}/` });
  crumbs.push({ name: title, path });
  return crumbs;
}

export async function generateMetadata(props: PageProps<"/rankings/[...segments]">): Promise<Metadata> {
  const { segments } = await props.params;
  const result = await loadRanking(segments);
  if (result.kind !== "found") return { robots: { index: false } };
  const r = result.ranking;
  const top = r.entries.slice(0, 3).map((e) => e.entity.name).join(", ");
  return buildMetadata({
    title: r.title,
    description: `${r.title}: ${pluralize(r.entries.length, r.entityType === "law_firm" ? "firm" : "lawyer")} ranked by the ${METHODOLOGY_VERSION} methodology${top ? `, led by ${top}` : ""}. Scores, verification status and sources.`,
    path: r.path ?? `/rankings/${segments.join("/")}/`,
    noindex: !rankingEligibility(r).indexable,
  });
}

export default async function RankingPage(props: PageProps<"/rankings/[...segments]">) {
  const { segments } = await props.params;
  const result = await loadRanking(segments);
  if (result.kind === "redirect") permanentRedirect(result.to);
  if (result.kind !== "found") notFound();

  const ranking = result.ranking;
  if (!rankingEligibility(ranking).exists) notFound();

  const path = ranking.path ?? `/rankings/${segments.join("/")}/`;
  const updated = formatDate(ranking.updatedAt);
  const related = (await allRankings())
    .filter((r) => r.id !== ranking.id && !r.isThin && (r.location?.stateSlug === ranking.location?.stateSlug || r.practiceArea?.slug === ranking.practiceArea?.slug))
    .slice(0, 4);
  const noun = ranking.entityType === "law_firm" ? "firm" : "lawyer";

  return (
    <>
      <JsonLd data={[rankingJsonLd(ranking, path), collectionPageJsonLd(ranking.title, path, `Ranking of ${noun}s by LexRank score.`)]} />
      <PageHeader crumbs={crumbsFor(ranking.title, path, ranking.location)} eyebrow={ranking.practiceArea?.name ?? "Ranking"} title={ranking.title}>
        <div className="page-header__meta">
          {updated && (
            <span>
              Updated <strong><time dateTime={isoDate(ranking.updatedAt)}>{updated}</time></strong>
            </span>
          )}
          <span>
            Methodology <strong>{METHODOLOGY_VERSION}</strong>
          </span>
          <span>
            <strong>{ranking.entries.length}</strong> {ranking.entries.length === 1 ? noun : `${noun}s`} ranked
          </span>
        </div>
      </PageHeader>

      <div className="container section layout-sidebar">
        <div className="stack">
          {ranking.isDemo && <DemoNotice />}
          {ranking.intro && <div className="prose" dangerouslySetInnerHTML={{ __html: ranking.intro }} />}
          <p className="muted" style={{ fontSize: "0.92rem" }}>
            Positions are ordered by organic LexRank score. Paid placements, where they exist, are always labelled and never affect a
            score or position.
          </p>
          <ol className="ranking-list" aria-label={ranking.title}>
            {ranking.entries.map((entry) => (
              <RankingEntry key={entry.entity.id} entry={entry} />
            ))}
          </ol>

          <section className="card" aria-labelledby="why-this-ranking">
            <h2 id="why-this-ranking" style={{ fontSize: "1.5rem" }}>
              Why this ranking?
            </h2>
            <p className="muted">
              LexRank evaluates publicly available and verified information including reputation, review strength, professional
              experience, practice-area relevance, professional credentials, local relevance and data quality. Ratings are adjusted for
              review volume, so a few perfect reviews cannot outrank hundreds of strong ones. Missing information is never guessed.
            </p>
            <Link className="link-arrow" href="/methodology/">
              Read the full methodology
            </Link>
          </section>

          {related.length > 0 && (
            <section>
              <h2>Related rankings</h2>
              <div className="grid grid--2">
                {related.map((r) => (
                  <RankingCard key={r.id} ranking={r} />
                ))}
              </div>
            </section>
          )}
        </div>
        <aside className="stack">
          <MethodologyPanel compact />
          <div className="card">
            <p className="panel-title">Explore</p>
            <ul className="chips">
              {ranking.location?.stateSlug && (
                <li>
                  <Link className="chip" href={`/states/${ranking.location.stateSlug}/`}>
                    {ranking.location.state}
                  </Link>
                </li>
              )}
              {ranking.location?.citySlug && (
                <li>
                  <Link className="chip" href={`/cities/${ranking.location.citySlug}/`}>
                    {ranking.location.city}
                  </Link>
                </li>
              )}
              {ranking.practiceArea && (
                <li>
                  <Link className="chip chip--brass" href={`/practice-areas/${ranking.practiceArea.slug}/`}>
                    {ranking.practiceArea.name}
                  </Link>
                </li>
              )}
            </ul>
          </div>
        </aside>
      </div>
    </>
  );
}
