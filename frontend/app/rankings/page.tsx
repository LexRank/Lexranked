import type { Metadata } from "next";
import { RankingCard } from "@/components/cards";
import { MethodologyPanel } from "@/components/Methodology";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice, EmptyState, UnavailableNotice } from "@/components/ui";
import { allRankings, load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import type { RankingSummary } from "@/types/api";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: "Lawyer Rankings by State, City and Practice Area",
  description: "Browse LexRanked lawyer and law firm rankings across the United States, calculated with a transparent, source-backed methodology.",
  path: "/rankings/",
});

function groupByState(rankings: RankingSummary[]): Array<[string, RankingSummary[]]> {
  const groups = new Map<string, RankingSummary[]>();
  for (const r of rankings) {
    const key = r.location?.state ?? "United States";
    groups.set(key, [...(groups.get(key) ?? []), r]);
  }
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
}

export default async function RankingsIndexPage() {
  const result = await load(allRankings);
  const rankings = result.ok ? result.data.filter((r) => !r.isThin) : [];

  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Rankings", path: "/rankings/" },
        ]}
        eyebrow="Rankings"
        title="Lawyer rankings"
        lead="Each ranking covers one location and practice area and is published only when enough verified data exists."
      />
      <div className="container section layout-sidebar">
        <div className="stack">
          {!result.ok && <UnavailableNotice />}
          {rankings.some((r) => r.isDemo) && <DemoNotice />}
          {result.ok && rankings.length === 0 && (
            <EmptyState title="No rankings published yet">
              <p>Rankings appear here once research and verification for a location are complete.</p>
            </EmptyState>
          )}
          {groupByState(rankings).map(([state, items]) => (
            <section key={state}>
              <h2>{state}</h2>
              <div className="grid grid--2">
                {items.map((r) => (
                  <RankingCard key={r.id} ranking={r} />
                ))}
              </div>
            </section>
          ))}
        </div>
        <aside>
          <MethodologyPanel />
        </aside>
      </div>
    </>
  );
}
