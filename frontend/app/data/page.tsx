import type { Metadata } from "next";
import Link from "next/link";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { DATA_INDEX_PATH, dataPath, publishedDataPages } from "@/lib/content/data-pages";
import { floridaStats } from "@/lib/content/state-stats";
import { collectionPageJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

const DESCRIPTION =
  "LexRanked's own data on Florida law and lawyers: every statute of limitations, the 20 judicial circuits, and figures on certification, languages, experience and law schools.";

export const metadata: Metadata = buildMetadata({ title: "Florida Legal Data and Lawyer Statistics", description: DESCRIPTION, path: DATA_INDEX_PATH });

export default async function DataIndexPage() {
  const stats = await floridaStats().catch(() => null);
  const pages = publishedDataPages(stats);
  return (
    <>
      <JsonLd data={collectionPageJsonLd("Florida legal data and lawyer statistics", DATA_INDEX_PATH, DESCRIPTION)} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Data", path: DATA_INDEX_PATH },
        ]}
        eyebrow="Data"
        title="Florida legal data and lawyer statistics"
        lead="Tables you will not find in one place elsewhere, each with its sources and the date it was last checked. Free to cite with a link."
      />
      <div className="container section stack">
        <div className="prose editorial__body">
          <h2>What data does LexRanked publish?</h2>
          <p>
            <strong>
              Reference tables built from the Florida Statutes, and statistics calculated from the {stats ? `${stats.lawyers} ` : ""}lawyer
              profiles we publish.
            </strong>{" "}
            The statistics update automatically whenever scores are recalculated.
          </p>
          <ul>
            {pages.map((p) => (
              <li key={p.slug}>
                <Link href={dataPath(p.slug)}>{p.title}</Link>: {p.teaser}
              </li>
            ))}
          </ul>
          <h2>Can I use this data?</h2>
          <p>
            <strong>Yes. Quote the figures with credit to LexRanked and a link to the page.</strong> For larger reuse, see our{" "}
            <Link href="/terms/">terms of use</Link> or <Link href="/contact/?topic=press">contact us</Link>.
          </p>
        </div>
      </div>
    </>
  );
}
