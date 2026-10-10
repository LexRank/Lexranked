import type { Metadata } from "next";
import { LawyerCard } from "@/components/cards";
import { ListingPage, parsePage } from "@/components/ListingPage";
import { listingEligibility } from "@/lib/content/eligibility";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getLawyers } from "@/lib/wordpress/api";

export const revalidate = 300;
const PER_PAGE = 24;

async function fetchPage(page: number) {
  return load(() => getLawyers({ page, per_page: PER_PAGE, orderby: "score", order: "desc" }));
}

export async function generateMetadata(props: PageProps<"/lawyers">): Promise<Metadata> {
  const page = parsePage((await props.searchParams).page);
  const result = await fetchPage(page);
  const items = result.ok ? result.data.data : [];
  return buildMetadata({
    title: page > 1 ? `Lawyers - Page ${page}` : "Lawyers ranked by LexRank score",
    description: "Browse lawyer profiles with LexRank scores, verification status, client ratings and sourced credentials.",
    path: page > 1 ? `/lawyers/?page=${page}` : "/lawyers/",
    noindex: !result.ok || !listingEligibility(items).indexable,
  });
}

export default async function LawyersPage(props: PageProps<"/lawyers">) {
  const page = parsePage((await props.searchParams).page);
  const result = await fetchPage(page);
  const items = result.ok ? result.data.data : [];
  return (
    <ListingPage
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Lawyers", path: "/lawyers/" },
      ]}
      eyebrow="Directory"
      title="Lawyers"
      lead="Profiles ordered by LexRank score. Every profile shows its verification status and the sources behind its facts."
      ok={result.ok}
      hasDemo={items.some((l) => l.isDemo)}
      empty={items.length === 0}
      basePath="/lawyers/"
      page={page}
      totalPages={result.ok ? (result.data.totalPages ?? 1) : 1}
    >
      {items.map((l) => (
        <LawyerCard key={l.id} lawyer={l} />
      ))}
    </ListingPage>
  );
}
