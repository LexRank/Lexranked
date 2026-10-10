import type { Metadata } from "next";
import { TermIndex } from "@/components/TermIndex";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { indexPageIndexable } from "@/lib/content/eligibility";
import { getCities } from "@/lib/wordpress/api";

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  // Thin until it links to enough pages (MIN_INDEX_PAGE_ENTRIES); still followed.
  const result = await load(async () => (await getCities()).data);
  return buildMetadata({
    title: "Lawyers by City",
    description: "Browse top-rated lawyers and law firms by U.S. city, with rankings by practice area built from verified, sourced data.",
    path: "/cities/",
    noindex: !indexPageIndexable(result.ok ? result.data.filter((c) => c.eligibility?.exists).length : 0),
  });
}

export default async function CitiesPage() {
  const result = await load(async () => (await getCities()).data);
  const items = result.ok ? result.data.map((c) => ({ ...c, sub: c.state.name })) : [];
  return (
    <TermIndex
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Cities", path: "/cities/" },
      ]}
      eyebrow="Locations"
      title="Lawyers by city"
      lead="City pages list rankings, lawyers and law firms with verified, sourced data."
      ok={result.ok}
      items={items}
    />
  );
}
