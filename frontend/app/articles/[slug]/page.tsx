import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ArticleCard, RankingCard } from "@/components/cards";
import { authorBySlug, authorPath } from "@/lib/content/authors";
import { JsonLd } from "@/components/JsonLd";
import { MethodologyPanel } from "@/components/Methodology";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice } from "@/components/ui";
import { addHeadingIds, categoryCounts, guideImage, rankingsForArticle, relatedArticles, wrapTables } from "@/lib/content/articles";
import { articleEligibility } from "@/lib/content/eligibility";
import { allArticles, allRankings, load } from "@/lib/data/loaders";
import { formatDate, isoDate } from "@/lib/format";
import { articleJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import { absoluteUrl } from "@/lib/seo/urls";
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
    image: { ...guideImage(article), url: absoluteUrl(guideImage(article).url) },
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

  const [articlesResult, rankingsResult] = await Promise.all([load(allArticles), load(allRankings)]);
  const all = articlesResult.ok ? articlesResult.data : [];
  const related = relatedArticles(article, all, 3);
  const latest = all.filter((a) => a.id !== article.id && !related.some((r) => r.id === a.id)).slice(0, 4);
  const categories = categoryCounts(all);
  const rankings = rankingsForArticle(article, rankingsResult.ok ? rankingsResult.data : [], article.relatedRankingId, 3);
  const { html, toc } = addHeadingIds(wrapTables(article.body));
  const image = guideImage(article);
  const category = article.categories.find((c) => c.slug !== "uncategorized") ?? null;

  const author = authorBySlug(article.author.slug);
  const published = formatDate(article.publishedAt);
  const updated = formatDate(article.updatedAt);
  const reviewed = formatDate(article.reviewedAt);
  return (
    <>
      <JsonLd data={articleJsonLd({ ...article, image: { ...image, url: absoluteUrl(image.url) } })} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Guides", path: "/articles/" },
          ...(category ? [{ name: category.name, path: `/articles/category/${category.slug}/` }] : []),
          { name: article.title, path: article.path },
        ]}
        eyebrow={category ? `Guide · ${category.name}` : "Guide"}
        title={article.title}
        lead={article.excerpt}
      >
        <div className="page-header__meta">
          <span>
            By <strong>{author ? <Link href={authorPath(author.slug)}>{author.name}</Link> : article.author.name}</strong>
          </span>
          {published && <span>Published {published}</span>}
          {updated && updated !== published && (
            <span>
              Updated <time dateTime={isoDate(article.updatedAt)}>{updated}</time>
            </span>
          )}
          <span>{article.readingMinutes} min read</span>
        </div>
      </PageHeader>
      <div className="container section layout-sidebar">
        <article className="stack">
          {article.isDemo && <DemoNotice />}
          {/* Every guide has a featured image: its own, or the LexRanked owl. Dimensions avoid layout shift. */}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={image.url} width={image.width} height={image.height} alt={image.alt} fetchPriority="high" style={{ width: "100%", height: "auto", borderRadius: "var(--radius)" }} />
          <div className="card editorial">
            <div className="prose editorial__body" dangerouslySetInnerHTML={{ __html: html }} />
          </div>
          {author && (
            <aside className="card author-box" aria-label="About the author">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={author.portrait.src} alt={author.portrait.alt} width={72} height={72} loading="lazy" />
              <div>
                <p style={{ margin: 0 }}>
                  <strong>
                    <Link href={authorPath(author.slug)}>{author.name}</Link>
                  </strong>{" "}
                  · {author.jobTitle}
                </p>
                <p className="muted" style={{ margin: "0.25rem 0 0", fontSize: "0.92rem" }}>
                  {author.summary}
                </p>
              </div>
            </aside>
          )}
          <p className="card__meta">
            {article.reviewedBy || reviewed ? (
              <>
                Editorially reviewed{article.reviewedBy ? ` by ${article.reviewedBy}` : ""}
                {reviewed ? ` on ${reviewed}` : ""}.{" "}
              </>
            ) : null}
            LexRanked guides are general information, not legal advice.
          </p>

          {rankings.length > 0 && (
            <section aria-labelledby="find-lawyer-heading" className="card cta-band">
              <h2 id="find-lawyer-heading" style={{ fontSize: "1.35rem", marginTop: 0 }}>
                {rankings[0]?.practiceArea ? `Find a ${rankings[0].practiceArea.name.toLowerCase()} lawyer` : "Find a lawyer"}
              </h2>
              <p className="muted" style={{ marginTop: 0 }}>
                Rankings built from verified license records and cited sources. Payment never changes a position.
              </p>
              <div className="grid grid--2">
                {rankings.map((r) => (
                  <RankingCard key={r.id} ranking={r} />
                ))}
              </div>
            </section>
          )}

          {related.length > 0 && (
            <section aria-labelledby="related-guides-heading">
              <h2 id="related-guides-heading" style={{ fontSize: "1.35rem" }}>
                Related guides
              </h2>
              <div className="grid grid--3">
                {related.map((a) => (
                  <ArticleCard key={a.id} article={a} />
                ))}
              </div>
            </section>
          )}
        </article>
        <aside className="stack">
          <div className="aside-sticky stack">
            {toc.length > 1 && (
              <nav className="card toc" aria-label="On this page">
                <p className="panel-title">On this page</p>
                <ol>
                  {toc.map((t) => (
                    <li key={t.id}>
                      <a href={`#${t.id}`}>{t.label}</a>
                    </li>
                  ))}
                </ol>
              </nav>
            )}
            {rankings[0]?.path && (
              <div className="card">
                <p className="panel-title">Find a lawyer</p>
                <ul className="link-list">
                  {rankings.map((r) => (
                    <li key={r.id}>
                      <Link href={r.path as string}>{r.title}</Link>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {categories.length > 0 && (
              <nav className="card" aria-label="Guide categories">
                <p className="panel-title">Categories</p>
                <ul className="link-list">
                  {categories.map((c) => (
                    <li key={c.slug}>
                      <Link href={`/articles/category/${c.slug}/`} aria-current={c.slug === category?.slug ? "page" : undefined}>
                        {c.name}
                      </Link>{" "}
                      <span className="muted">({c.count})</span>
                    </li>
                  ))}
                </ul>
              </nav>
            )}
            {latest.length > 0 && (
              <nav className="card" aria-label="More guides">
                <p className="panel-title">More guides</p>
                <ul className="link-list">
                  {latest.map((a) => (
                    <li key={a.id}>
                      <Link href={a.path}>{a.title}</Link>
                    </li>
                  ))}
                </ul>
              </nav>
            )}
            <MethodologyPanel compact />
          </div>
        </aside>
      </div>
    </>
  );
}
