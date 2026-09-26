import type { Metadata } from "next";
import { TermIndex } from "@/components/TermIndex";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getPracticeAreas } from "@/lib/wordpress/api";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: "Lawyers by Practice Area",
  description: "Browse top-rated lawyers by practice area, from personal injury to family and criminal defense.",
  path: "/practice-areas/",
});

export default async function PracticeAreasPage() {
  const result = await load(async () => (await getPracticeAreas()).data);
  return (
    <TermIndex
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Practice areas", path: "/practice-areas/" },
      ]}
      eyebrow="Practice areas"
      title="Lawyers by practice area"
      lead="Each practice area is ranked separately by location, so scores compare like with like."
      ok={result.ok}
      items={result.ok ? result.data : []}
    />
  );
}
