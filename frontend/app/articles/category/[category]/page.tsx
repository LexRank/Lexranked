import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ArticleCard } from "@/components/cards";
import { GuideCategories } from "@/components/GuideCategories";
import { ListingPage, parsePage } from "@/components/ListingPage";
import { categoryCounts } from "@/lib/content/articles";
import { articleEligibility, indexPageIndexable } from "@/lib/content/eligibility";
import { allArticles, load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";
import { inSentence } from "@/lib/format";

export const revalidate = 300;
const PER_PAGE = 12;

export function generateStaticParams() {
  return [];
}

const loadCategory = cache(async (slug: string) => {
  const result = await load(allArticles);
  const all = result.ok ? result.data : [];
  const categories = categoryCounts(all);
  const category = categories.find((c) => c.slug === slug) ?? null;
  return { categories, category, items: all.filter((a) => a.categories.some((c) => c.slug === slug)) };
});

export async function generateMetadata(props: PageProps<"/articles/category/[category]">): Promise<Metadata> {
  const { category: slug } = await props.params;
  const page = parsePage((await props.searchParams).page);
  const { category, items } = await loadCategory(slug);
  if (!category) return { robots: { index: false } };
  const path = `/articles/category/${category.slug}/`;
  return buildMetadata({
    title: page > 1 ? `${category.name} Guides - Page ${page}` : `${category.name} Guides`,
    description: `Guides on ${inSentence(category.name)}: practical answers on your rights, deadlines, costs and how to choose a lawyer, from LexRanked.`,
    path: page > 1 ? `${path}?page=${page}` : path,
    // A category is worth indexing once it holds enough guides.
    noindex: !indexPageIndexable(items.filter((a) => articleEligibility(a).indexable).length),
  });
}

export default async function GuideCategoryPage(props: PageProps<"/articles/category/[category]">) {
  const { category: slug } = await props.params;
  const page = parsePage((await props.searchParams).page);
  const { categories, category, items } = await loadCategory(slug);
  if (!category) notFound();
  const totalPages = Math.max(1, Math.ceil(items.length / PER_PAGE));
  const shown = items.slice((page - 1) * PER_PAGE, page * PER_PAGE);
  return (
    <ListingPage
      crumbs={[
        { name: "Home", path: "/" },
        { name: "Guides", path: "/articles/" },
        { name: category.name, path: `/articles/category/${category.slug}/` },
      ]}
      eyebrow="Guides"
      title={`${category.name} guides`}
      lead={`Everything we have published on ${inSentence(category.name)}, newest first.`}
      ok
      hasDemo={shown.some((a) => a.isDemo)}
      empty={shown.length === 0}
      basePath={`/articles/category/${category.slug}/`}
      page={page}
      totalPages={totalPages}
      nav={<GuideCategories categories={categories} current={category.slug} />}
    >
      {shown.map((a) => (
        <ArticleCard key={a.id} article={a} />
      ))}
    </ListingPage>
  );
}
