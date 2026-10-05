import type { ReactNode } from "react";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { rankingPageJsonLd } from "@/lib/seo/jsonld";

/** One section: a heading, the direct answer (bold, highlighted) and the detail. */
export interface TrustSection {
  id: string;
  heading: string;
  answer: ReactNode;
  children?: ReactNode;
}

/**
 * Layout of the trust and legal pages (about, editorial policy, privacy,
 * terms, disclaimer): answer-first sections with a table of contents and
 * the date the page was last updated.
 */
export function TrustPage({
  path,
  crumb,
  title,
  lead,
  description,
  updated,
  sections,
  children,
}: {
  path: string;
  crumb: string;
  title: string;
  lead: ReactNode;
  description: string;
  /** ISO date (YYYY-MM-DD) the text was last changed. */
  updated: string;
  sections: TrustSection[];
  children?: ReactNode;
}) {
  const updatedLabel = new Date(`${updated}T00:00:00Z`).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric", timeZone: "UTC" });
  return (
    <>
      <JsonLd data={rankingPageJsonLd({ name: title, path, description, dateModified: updated, reviewedBy: null, reviewedAt: null })} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: crumb, path },
        ]}
        eyebrow="Trust"
        title={title}
        lead={lead}
      />
      <div className="container section stack">
        <nav className="card toc" aria-label="On this page">
          <h2 style={{ fontSize: "1rem", margin: "0 0 0.5rem" }}>On this page</h2>
          <ol style={{ margin: 0 }}>
            {sections.map((s) => (
              <li key={s.id}>
                <a href={`#${s.id}`}>{s.heading}</a>
              </li>
            ))}
          </ol>
        </nav>
        <article className="prose editorial__body">
          {sections.map((s) => (
            <section key={s.id} aria-labelledby={s.id}>
              <h2 id={s.id}>{s.heading}</h2>
              <p>
                <strong>{s.answer}</strong>
              </p>
              {s.children}
            </section>
          ))}
          {children}
          <p className="muted" style={{ fontSize: "0.88rem", marginTop: "2rem" }}>
            Last updated: <time dateTime={updated}>{updatedLabel}</time>
          </p>
        </article>
      </div>
    </>
  );
}
