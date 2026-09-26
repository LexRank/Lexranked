import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { HubPage } from "@/components/HubPage";
import { hubEligibility } from "@/lib/content/eligibility";
import { allRankings } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getCities, getLawFirms, getLawyers, getStates } from "@/lib/wordpress/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const loadState = cache(async (slug: string) => {
  const state = (await getStates()).data.find((s) => s.slug === slug);
  if (!state) return null;
  const [lawyers, firms, cities, rankings] = await Promise.all([
    getLawyers({ state: slug, per_page: 12, orderby: "score", order: "desc" }),
    getLawFirms({ state: slug, per_page: 6, orderby: "score", order: "desc" }),
    getCities(slug),
    allRankings(),
  ]);
  return {
    state,
    lawyers: lawyers.data,
    firms: firms.data,
    cities: cities.data,
    rankings: rankings.filter((r) => !r.isThin && r.location?.stateSlug === slug),
    eligibility: hubEligibility(state, lawyers.data),
  };
});

export async function generateMetadata(props: PageProps<"/states/[state]">): Promise<Metadata> {
  const { state } = await props.params;
  const data = await loadState(state);
  if (!data?.eligibility.exists) return { robots: { index: false } };
  return buildMetadata({
    title: `Top-Rated Lawyers in ${data.state.name}`,
    description: `Top-rated lawyers and law firms in ${data.state.name}: ${data.state.lawyerCount} lawyer profiles and ${data.rankings.length} rankings by practice area, scored with a transparent methodology.`,
    path: data.state.path,
    noindex: !data.eligibility.indexable,
  });
}

export default async function StatePage(props: PageProps<"/states/[state]">) {
  const { state } = await props.params;
  const data = await loadState(state);
  if (!data || !data.eligibility.exists) notFound();
  const { state: s } = data;
  return (
    <HubPage
      crumbs={[
        { name: "Home", path: "/" },
        { name: "States", path: "/states/" },
        { name: s.name, path: s.path },
      ]}
      path={s.path}
      eyebrow={s.code ? `State · ${s.code}` : "State"}
      title={`Top-rated lawyers in ${s.name}`}
      lead={`Rankings, lawyers and law firms in ${s.name}, scored with the LexRank methodology from sourced, verified data.`}
      counts={s}
      rankings={data.rankings}
      lawyers={data.lawyers}
      firms={data.firms}
      cities={data.cities}
      lawyersHeading={`Highest-scoring lawyers in ${s.name}`}
      firmsHeading={`Law firms in ${s.name}`}
    />
  );
}
