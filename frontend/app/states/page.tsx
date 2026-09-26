import type { Metadata } from "next";
import { TermIndex } from "@/components/TermIndex";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getStates } from "@/lib/wordpress/api";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: "Lawyers by State",
  description: "Browse top-rated lawyers and law firms by U.S. state, with rankings by practice area.",
  path: "/states/",
});

export default async function StatesPage() {
  const result = await load(async () => (await getStates()).data);
  const items = result.ok ? result.data.map((s) => ({ ...s, sub: s.code })) : [];
  return (
    <TermIndex
      crumbs={[
        { name: "Home", path: "/" },
        { name: "States", path: "/states/" },
      ]}
      eyebrow="Locations"
      title="Lawyers by state"
      lead="We are expanding state by state, publishing only where enough verified data exists."
      ok={result.ok}
      items={items}
    />
  );
}
