import type { Metadata } from "next";
import { ArticleCard } from "@/components/cards";
import { ListingPage, parsePage } from "@/components/ListingPage";
import { articleEligibility, listingEligibility } from "@/lib/content/eligibility";
import { load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { getArticles } from "@/lib/wordpress/api";

export const revalidate = 300;
const PER_PAGE = 12;

async function fetchPage(page: number) {
  return load(() => getArticles({ page, per_page: PER_PAGE }));
}

export async function generateMetadata(props: PageProps<"/articles">): Promise<Metadata> {
  const page = parsePage((await props.searchParams).page);
  const result = await fetchPage(page);
  const items = result.ok ? result.data.data : [];
  return buildMetadata({
    title: page > 1 ? `Guides – Page ${page}` : "Guides to choosing a lawyer",
    description: "Editorial guides from LexRanked: how rankings work, how to check a lawyer's credentials and what to ask before hiring.",
    path: page > 1 ? `/articles/?page=${page}` : "/articles/",
    // A listing of only demo or thin guides is not worth indexing.
    noindex: !result.ok || !listingEligibility(items.map((a) => ({ isDemo: !articleEligibility(a).indexable }))).indexable,
  });
}

export default async function ArticlesPage(props: PageProps<"/articles">) {
  const page = parsePage((await props.searchParams).page);
  const result = await fetchPage(page);
  const items = result.ok ? result.data.data : [];
  return (
    <ListingPage
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Guides", path: "/articles/" },
      ]}
      eyebrow="Editorial"
      title="Guides"
      lead="Practical guides written and reviewed by our editors. Every fact about lawyers and rankings links back to sourced data."
      ok={result.ok}
      hasDemo={items.some((a) => a.isDemo)}
      empty={items.length === 0}
      basePath="/articles/"
      page={page}
      totalPages={result.ok ? (result.data.totalPages ?? 1) : 1}
    >
      {items.map((a) => (
        <ArticleCard key={a.id} article={a} />
      ))}
    </ListingPage>
  );
}
