import type { Metadata } from "next";
import { FirmCard } from "@/components/cards";
import { ListingPage, parsePage } from "@/components/ListingPage";
import { listingEligibility } from "@/lib/content/eligibility";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getLawFirms } from "@/lib/wordpress/api";

export const revalidate = 300;
const PER_PAGE = 24;

async function fetchPage(page: number) {
  return load(() => getLawFirms({ page, per_page: PER_PAGE, orderby: "score", order: "desc" }));
}

export async function generateMetadata(props: PageProps<"/law-firms">): Promise<Metadata> {
  const page = parsePage((await props.searchParams).page);
  const result = await fetchPage(page);
  const items = result.ok ? result.data.data : [];
  return buildMetadata({
    title: page > 1 ? `Law Firms – Page ${page}` : "Law firms ranked by LexRank score",
    description: "Browse law firm profiles with LexRank scores, verification status, lawyers and sourced facts.",
    path: page > 1 ? `/law-firms/?page=${page}` : "/law-firms/",
    noindex: !result.ok || !listingEligibility(items).indexable,
  });
}

export default async function LawFirmsPage(props: PageProps<"/law-firms">) {
  const page = parsePage((await props.searchParams).page);
  const result = await fetchPage(page);
  const items = result.ok ? result.data.data : [];
  return (
    <ListingPage
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Law firms", path: "/law-firms/" },
      ]}
      eyebrow="Directory"
      title="Law firms"
      lead="Firms ordered by LexRank score, with their lawyers, verification status and sources."
      ok={result.ok}
      hasDemo={items.some((f) => f.isDemo)}
      empty={items.length === 0}
      basePath="/law-firms/"
      page={page}
      totalPages={result.ok ? (result.data.totalPages ?? 1) : 1}
    >
      {items.map((f) => (
        <FirmCard key={f.id} firm={f} />
      ))}
    </ListingPage>
  );
}
