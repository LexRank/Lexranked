import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArticleCard } from "@/components/cards";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { AUTHORS, authorBySlug, authorPath } from "@/lib/content/authors";
import { allArticles, load } from "@/lib/data/loaders";
import { authorJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

export function generateStaticParams() {
  return AUTHORS.map((a) => ({ slug: a.slug }));
}

export async function generateMetadata(props: PageProps<"/authors/[slug]">): Promise<Metadata> {
  const { slug } = await props.params;
  const author = authorBySlug(slug);
  if (!author) return { robots: { index: false } };
  return buildMetadata({ title: `${author.name}, ${author.jobTitle}`, description: author.summary, path: authorPath(author.slug) });
}

export default async function AuthorPage(props: PageProps<"/authors/[slug]">) {
  const { slug } = await props.params;
  const author = authorBySlug(slug);
  if (!author) notFound();
  const articles = await load(allArticles);
  const guides = articles.ok ? articles.data.filter((a) => a.author.slug === author.slug) : [];
  return (
    <>
      <JsonLd data={authorJsonLd(author)} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "About", path: "/about/" },
          { name: author.name, path: authorPath(author.slug) },
        ]}
        eyebrow={`${author.jobTitle} · LexRanked`}
        title={author.name}
        lead={author.summary}
      />
      <div className="container section stack">
        <div className="author-profile">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img className="author-profile__portrait" src={author.portrait.src} alt={author.portrait.alt} width={author.portrait.width} height={author.portrait.height} />
          <div className="prose editorial__body">
            <h2>Who is {author.name}?</h2>
            <p>
              <strong>{author.summary}</strong>
            </p>
            <table>
              <tbody>
                {author.facts.map(([label, value]) => (
                  <tr key={label}>
                    <td>{label}</td>
                    <td>{value}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <h2>What does {author.name.split(" ")[0]} cover at LexRanked?</h2>
            <p>
              <strong>He edits guides about the financial side of hiring a lawyer and of legal claims.</strong>
            </p>
            <ul>
              {author.focus.map((f) => (
                <li key={f}>{f}</li>
              ))}
            </ul>
            <p>
              Every rule, deadline and figure in his guides is checked against the statute, court rule or official source linked in the guide,
              following our <Link href="/editorial-policy/">editorial policy</Link>.
            </p>

            <h2>What is his background?</h2>
            <p>
              <strong>{author.background}</strong>
            </p>

            <h2>Is {author.name.split(" ")[0]} a lawyer?</h2>
            <p>
              <strong>No. {author.name} is not a lawyer, and LexRanked does not give legal advice.</strong> The legal rules in his guides are taken
              from primary sources and linked so you can check them; for advice on your situation, speak to a lawyer licensed in your state.
            </p>

            <h2>What does he do outside work?</h2>
            <p>
              <strong>{author.interests}</strong>
            </p>
          </div>
        </div>

        <div className="author-photos">
          {author.photos.map((p) => (
            // eslint-disable-next-line @next/next/no-img-element
            <img key={p.src} src={p.src} alt={p.alt} width={p.width} height={p.height} loading="lazy" />
          ))}
        </div>

        {guides.length > 0 && (
          <section aria-labelledby="author-guides-heading" className="stack">
            <h2 id="author-guides-heading">Guides by {author.name}</h2>
            <div className="grid grid--2">
              {guides.map((a) => (
                <ArticleCard key={a.id} article={a} />
              ))}
            </div>
          </section>
        )}
      </div>
    </>
  );
}
