import { redirectIfMoved } from "@/lib/content/moved";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { HubPage } from "@/components/HubPage";
import { hubEligibility } from "@/lib/content/eligibility";
import { allRankings } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getLawFirms, getLawyers, getPracticeAreas, getPlacements } from "@/lib/wordpress/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const loadArea = cache(async (slug: string) => {
  const area = (await getPracticeAreas()).data.find((p) => p.slug === slug);
  if (!area) return null;
  const [lawyers, firms, rankings, featured] = await Promise.all([
    getLawyers({ practice_area: slug, per_page: 12, orderby: "score", order: "desc" }),
    getLawFirms({ practice_area: slug, per_page: 6, orderby: "score", order: "desc" }),
    allRankings(),
    getPlacements({ product: "featured", practice_area: slug }),
  ]);
  return {
    area,
    featured,
    lawyers: lawyers.data,
    firms: firms.data,
    rankings: rankings.filter((r) => !r.isThin && r.practiceArea?.slug === slug),
    eligibility: hubEligibility(area, lawyers.data),
  };
});

export async function generateMetadata(props: PageProps<"/practice-areas/[practiceArea]">): Promise<Metadata> {
  const { practiceArea } = await props.params;
  const data = await loadArea(practiceArea);
  if (!data?.eligibility.exists) return { robots: { index: false } };
  return buildMetadata({
    title: `Top-Rated ${data.area.name} Lawyers`,
    description: `${data.area.name} lawyers and law firms ranked with the LexRank methodology: ${data.area.lawyerCount} profiles with scores, verification status and sources.`,
    path: data.area.path,
    noindex: !data.eligibility.indexable,
  });
}

export default async function PracticeAreaPage(props: PageProps<"/practice-areas/[practiceArea]">) {
  const { practiceArea } = await props.params;
  const data = await loadArea(practiceArea);
  if (!data) await redirectIfMoved("practice_area", practiceArea, `/practice-areas/${practiceArea}/`);
  if (!data || !data.eligibility.exists) notFound();
  const a = data.area;
  return (
    <HubPage
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Practice areas", path: "/practice-areas/" },
        { name: a.name, path: a.path },
      ]}
      path={a.path}
      eyebrow="Practice area"
      title={`Top-rated ${a.name.toLowerCase()} lawyers`}
      lead={a.description || `${a.name} lawyers and law firms, ranked by location with the LexRank methodology.`}
      counts={a}
      featured={data.featured}
      content={a.content ?? null}
      rankings={data.rankings}
      lawyers={data.lawyers}
      firms={data.firms}
      lawyersHeading={`Highest-scoring ${a.name.toLowerCase()} lawyers`}
      firmsHeading={`${a.name} law firms`}
    />
  );
}
