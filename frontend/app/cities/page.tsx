import type { Metadata } from "next";
import { TermIndex } from "@/components/TermIndex";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getCities } from "@/lib/wordpress/api";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: "Lawyers by City",
  description: "Browse top-rated lawyers and law firms by U.S. city.",
  path: "/cities/",
});

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
