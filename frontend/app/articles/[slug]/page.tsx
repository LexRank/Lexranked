import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { JsonLd } from "@/components/JsonLd";
import { MethodologyPanel } from "@/components/Methodology";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice } from "@/components/ui";
import { articleEligibility } from "@/lib/content/eligibility";
import { formatDate } from "@/lib/format";
import { articleJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import { getArticle } from "@/lib/wordpress/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const loadArticle = cache((slug: string) => getArticle(slug));

export async function generateMetadata(props: PageProps<"/articles/[slug]">): Promise<Metadata> {
  const { slug } = await props.params;
  const article = await loadArticle(slug);
  if (!article || decodeURIComponent(slug) !== article.slug) return { robots: { index: false } };
  return buildMetadata({
    title: article.title,
    description: article.excerpt || article.title,
    path: article.path,
    type: "article",
    image: article.image,
    publishedTime: article.publishedAt,
    modifiedTime: article.updatedAt,
    noindex: !articleEligibility(article).indexable,
  });
}

export default async function ArticlePage(props: PageProps<"/articles/[slug]">) {
  const { slug } = await props.params;
  const article = await loadArticle(slug);
  if (!article) notFound();
  // Only the canonical slug URL exists (the API also resolves numeric IDs).
  if (decodeURIComponent(slug) !== article.slug) notFound();

  const published = formatDate(article.publishedAt);
  const updated = formatDate(article.updatedAt);
  const reviewed = formatDate(article.reviewedAt);
  return (
    <>
      <JsonLd data={articleJsonLd(article)} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Guides", path: "/articles/" },
          { name: article.title, path: article.path },
        ]}
        eyebrow={article.categories[0] ? `Guide · ${article.categories[0].name}` : "Guide"}
        title={article.title}
        lead={article.excerpt}
      >
        <div className="page-header__meta">
          <span>
            By <strong>{article.author.name}</strong>
          </span>
          {published && <span>Published {published}</span>}
          {updated && updated !== published && <span>Updated {updated}</span>}
          <span>{article.readingMinutes} min read</span>
        </div>
      </PageHeader>
      <div className="container section layout-sidebar">
        <article className="stack">
          {article.isDemo && <DemoNotice />}
          {article.image && (
            // Remote CMS image; dimensions come from the API to avoid layout shift.
            // eslint-disable-next-line @next/next/no-img-element
            <img src={article.image.url} width={article.image.width} height={article.image.height} alt={article.image.alt} style={{ width: "100%", height: "auto", borderRadius: "var(--radius)" }} />
          )}
          <div className="card editorial">
            <div className="prose editorial__body" dangerouslySetInnerHTML={{ __html: article.body }} />
          </div>
          {(article.reviewedBy || reviewed) && (
            <p className="card__meta">
              Editorially reviewed{article.reviewedBy ? ` by ${article.reviewedBy}` : ""}
              {reviewed ? ` on ${reviewed}` : ""}. LexRanked guides are general information, not legal advice.
            </p>
          )}
        </article>
        <aside className="stack">
          <div className="aside-sticky stack">
            {article.relatedRanking?.path && (
              <div className="card">
                <p className="panel-title">Related ranking</p>
                <p style={{ margin: "0 0 0.75rem" }}>{article.relatedRanking.title}</p>
                <Link className="link-arrow" href={article.relatedRanking.path}>
                  View the ranking
                </Link>
              </div>
            )}
            <MethodologyPanel compact />
          </div>
        </aside>
      </div>
    </>
  );
}
