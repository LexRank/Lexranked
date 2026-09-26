import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { HubPage } from "@/components/HubPage";
import { hubEligibility } from "@/lib/content/eligibility";
import { allRankings } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getCities, getLawFirms, getLawyers } from "@/lib/wordpress/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const loadCity = cache(async (slug: string) => {
  const city = (await getCities()).data.find((c) => c.slug === slug);
  if (!city) return null;
  const [lawyers, firms, rankings] = await Promise.all([
    getLawyers({ city: slug, per_page: 12, orderby: "score", order: "desc" }),
    getLawFirms({ city: slug, per_page: 6, orderby: "score", order: "desc" }),
    allRankings(),
  ]);
  return {
    city,
    lawyers: lawyers.data,
    firms: firms.data,
    rankings: rankings.filter((r) => !r.isThin && r.location?.citySlug === slug),
    eligibility: hubEligibility(city, lawyers.data),
  };
});

function label(city: { name: string; state: { code: string | null; name: string | null } }): string {
  return city.state.code ? `${city.name}, ${city.state.code}` : city.name;
}

export async function generateMetadata(props: PageProps<"/cities/[city]">): Promise<Metadata> {
  const { city } = await props.params;
  const data = await loadCity(city);
  if (!data?.eligibility.exists) return { robots: { index: false } };
  return buildMetadata({
    title: `Top-Rated Lawyers in ${label(data.city)}`,
    description: `Top-rated lawyers and law firms in ${label(data.city)}: ${data.city.lawyerCount} lawyer profiles with LexRank scores, verification status and sources.`,
    path: data.city.path,
    noindex: !data.eligibility.indexable,
  });
}

export default async function CityPage(props: PageProps<"/cities/[city]">) {
  const { city } = await props.params;
  const data = await loadCity(city);
  if (!data || !data.eligibility.exists) notFound();
  const c = data.city;
  const crumbs = [{ name: "Home", path: "/" }];
  if (c.state.slug && c.state.name) crumbs.push({ name: c.state.name, path: `/states/${c.state.slug}/` });
  else crumbs.push({ name: "Cities", path: "/cities/" });
  crumbs.push({ name: c.name, path: c.path });
  return (
    <HubPage
      crumbs={crumbs}
      path={c.path}
      eyebrow={c.state.name ? `City · ${c.state.name}` : "City"}
      title={`Top-rated lawyers in ${label(c)}`}
      lead={`Rankings, lawyers and law firms in ${label(c)}, scored with the LexRank methodology from sourced, verified data.`}
      counts={c}
      rankings={data.rankings}
      lawyers={data.lawyers}
      firms={data.firms}
      lawyersHeading={`Highest-scoring lawyers in ${c.name}`}
      firmsHeading={`Law firms in ${c.name}`}
    />
  );
}
