import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { cache } from "react";
import { CompareLinks, RankingCard, RankingEntry } from "@/components/cards";
import { JsonLd } from "@/components/JsonLd";
import { MethodologyPanel } from "@/components/Methodology";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice } from "@/components/ui";
import { rankingEligibility } from "@/lib/content/eligibility";
import { resolveRanking } from "@/lib/content/rankings";
import { rankingAnswer, rankingFacts } from "@/lib/content/rankingFacts";
import { AboutRanking, EditorialBody, FaqSection, OnThisPage, RankingOverview, RankingSources, RelatedQuestions } from "@/components/ranking/RankingContent";
import { relatedQuestions } from "@/lib/content/relatedQuestions";
import { allRankings } from "@/lib/data/loaders";
import { formatDate, isoDate, pluralize } from "@/lib/format";
import { methodologyLabel } from "@/lib/methodology";
import { rankingJsonLd, rankingPageJsonLd, type Crumb } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import { getPlacements, getRanking } from "@/lib/wordpress/api";
import type { RankingContextDto } from "@/types/api";
import { PlacementBlock } from "@/components/commercial/Commercial";

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

function crumbsFor(
  title: string,
  path: string,
  location: { state: string | null; stateSlug: string | null; city: string | null; citySlug: string | null } | null,
  parent?: { title: string; path: string | null } | null,
): Crumb[] {
  const crumbs: Crumb[] = [
    { name: "Home", path: "/" },
    { name: "Rankings", path: "/rankings/" },
  ];
  if (location?.state && location.stateSlug) crumbs.push({ name: location.state, path: `/states/${location.stateSlug}/` });
  if (location?.city && location.citySlug) crumbs.push({ name: location.city, path: `/cities/${location.citySlug}/` });
  if (parent?.path) crumbs.push({ name: parent.title, path: parent.path });
  crumbs.push({ name: title, path });
  return crumbs;
}

export async function generateMetadata(props: PageProps<"/rankings/[...segments]">): Promise<Metadata> {
  const { segments } = await props.params;
  const result = await loadRanking(segments);
  if (result.kind !== "found") return { robots: { index: false } };
  const r = result.ranking;
  const answer = rankingAnswer(r);
  return buildMetadata({
    title: r.title,
    description: answer || `${r.title}: ${pluralize(r.entries.length, r.entityType === "law_firm" ? "firm" : "lawyer")} ranked by the ${methodologyLabel(r.entries[0]?.scoreVersion)} methodology.`,
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
  const sponsored = ranking.entries.length > 0 ? await getPlacements({ product: "sponsored", ranking: ranking.id }) : [];
  const rankings = await allRankings();
  const context = ranking.context ?? null;
  // Narrower "best for" rankings that passed their data threshold (Etap F).
  const narrower = rankings.filter((r) => r.context?.parent?.id === ranking.id && !r.isThin && r.path);
  const related = rankings
    .filter((r) => r.id !== ranking.id && !r.isThin && !narrower.includes(r) && (r.location?.stateSlug === ranking.location?.stateSlug || r.practiceArea?.slug === ranking.practiceArea?.slug))
    .slice(0, 4);
  const noun = ranking.entityType === "law_firm" ? "firm" : "lawyer";
  const facts = rankingFacts(ranking);
  const answer = rankingAnswer(ranking, facts);
  const questions = relatedQuestions(ranking, rankings);
  const toc = [
    { href: "#ranking", label: "The ranking" },
    ...(ranking.body.trim() ? [{ href: "#guide", label: "Guide" }] : []),
    ...((ranking.sources ?? []).length > 0 ? [{ href: "#sources", label: "Sources" }] : []),
    { href: "#methodology", label: "Why this ranking?" },
    ...(ranking.faq.length > 0 ? [{ href: "#faq", label: "FAQ" }] : []),
    ...(questions.length > 0 ? [{ href: "#related-questions", label: "Related questions" }] : []),
    { href: "#about", label: "About this ranking" },
    ...(related.length > 0 ? [{ href: "#related", label: "Related rankings" }] : []),
  ];

  return (
    <>
      <JsonLd
        data={[
          rankingJsonLd(ranking, path),
          rankingPageJsonLd({
            name: ranking.title,
            path,
            description: answer,
            dateModified: ranking.updatedAt,
            reviewedBy: ranking.editorial.reviewedBy,
            reviewedAt: ranking.editorial.reviewedAt,
          }),
        ]}
      />
      <PageHeader
        crumbs={crumbsFor(ranking.title, path, ranking.location, context?.parent)}
        eyebrow={[ranking.practiceArea?.name ?? "Ranking", context?.label].filter(Boolean).join(" · ")}
        title={ranking.title}
      >
        <div className="page-header__meta">
          {updated && (
            <span>
              Updated <strong><time dateTime={isoDate(ranking.updatedAt)}>{updated}</time></strong>
            </span>
          )}
          <span>
            Methodology <strong>{methodologyLabel(ranking.entries[0]?.scoreVersion)}</strong>
          </span>
          <span>
            <strong>{ranking.entries.length}</strong> {ranking.entries.length === 1 ? noun : `${noun}s`} ranked
          </span>
          {ranking.editorial.reviewedBy && (
            <span>
              Reviewed by <strong>{ranking.editorial.reviewedBy}</strong>
            </span>
          )}
        </div>
      </PageHeader>

      <div className="container section layout-sidebar">
        <div className="stack">
          {ranking.isDemo && <DemoNotice />}
          <RankingOverview answer={answer} summary={ranking.summary} facts={facts} noun={`${noun}s`} />

          <section id="ranking" aria-labelledby="ranking-heading" className="stack" style={{ gap: "1rem" }}>
            <div>
              <h2 id="ranking-heading" style={{ fontSize: "1.5rem", marginBottom: "0.25rem" }}>
                The ranking
              </h2>
              <p className="muted" style={{ fontSize: "0.9rem", margin: 0 }}>
                Ordered by organic LexRank score. Paid placements, where they exist, are always labelled and never affect a score or
                position.
              </p>
              {context && <ContextNote context={context} noun={noun} />}
            </div>
            <ol className="ranking-list" aria-label={ranking.title}>
              {ranking.entries.map((entry) => (
                <RankingEntry key={entry.entity.id} entry={entry} context={context} />
              ))}
            </ol>
            <CompareLinks ranking={ranking} />
            {narrower.length > 0 && (
              <nav className="card" aria-labelledby="narrower-heading" style={{ padding: "1rem 1.25rem" }}>
                <p id="narrower-heading" className="panel-title" style={{ margin: 0 }}>
                  Narrower rankings
                </p>
                <ul className="chips" style={{ marginTop: "0.5rem" }}>
                  {narrower.map((r) => (
                    <li key={r.id}>
                      <Link className="chip" href={r.path as string}>
                        {r.context?.label}
                      </Link>
                    </li>
                  ))}
                </ul>
              </nav>
            )}
          </section>

          <PlacementBlock placements={sponsored} product="sponsored" />

          <RankingSources sources={ranking.sources ?? []} noun={noun} />

          <EditorialBody html={ranking.body} />

          <section id="methodology" className="card" aria-labelledby="why-this-ranking">
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

          <FaqSection items={ranking.faq} />
          <RelatedQuestions items={questions} />
          <AboutRanking ranking={ranking} facts={facts} />
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


          {related.length > 0 && (
            <section id="related">
              <h2>Related rankings</h2>
              <div className="grid grid--2">
                {related.map((r) => (
                  <RankingCard key={r.id} ranking={r} />
                ))}
              </div>
            </section>
          )}
        </div>
        <aside>
          <div className="stack aside-sticky">
            <OnThisPage links={toc} />
            <MethodologyPanel compact />
          </div>
        </aside>
      </div>
    </>
  );
}

/** Who a contextual ranking includes, and on what evidence (Etap F). */
function ContextNote({ context, noun }: { context: RankingContextDto; noun: string }) {
  const e = context.eligibility;
  const what =
    context.type === "language"
      ? `list ${context.value} among their languages`
      : context.type === "client_type"
        ? `list ${context.value} among the clients they serve`
        : `list ${context.label.toLowerCase()} among the case types they handle`;
  return (
    <p className="context-note">
      <strong>Who is included:</strong> only {noun}s whose sourced records {what}: {e.qualified} of the {e.parentCount} in{" "}
      {context.parent?.path ? <Link href={context.parent.path}>{context.parent.title}</Link> : "the broader ranking"}, {e.verified} of them confirmed by a
      verified fact. Scores are the same LexRank scores; the context selects who is ranked and never changes a score.
    </p>
  );
}
