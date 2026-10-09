import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { figuresSection } from "@/components/DataSources";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPageReady, dataPath, percent } from "@/lib/content/data-pages";
import { floridaStats } from "@/lib/content/state-stats";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

const PAGE = dataPage("florida-lawyers-law-schools");
const PATH = dataPath(PAGE.slug);
const DESCRIPTION =
  "The law schools most often attended by the board-certified Florida lawyers LexRanked tracks, with counts and shares, from the education on their Florida Bar records.";

export async function generateMetadata(): Promise<Metadata> {
  const stats = await floridaStats().catch(() => null);
  return buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH, noindex: !dataPageReady(PAGE, stats) });
}

export default async function LawSchoolsPage() {
  const stats = await floridaStats();
  if (!dataPageReady(PAGE, stats)) notFound();
  const updated = stats.calculatedAt.slice(0, 10);
  const { sample, items } = stats.schools;
  const first = items[0];

  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "Where did Florida's top lawyers go to law school?",
      answer: first
        ? `${first.name} leads: ${first.count} of the ${sample} lawyers with a law school on record (${percent(first.count, sample)}) studied there.`
        : "The table lists the schools on record.",
      children: (
        <ul>
          <li>
            <strong>
              The five most common:{" "}
              {items
                .slice(0, 5)
                .map((s) => `${s.name} (${s.count})`)
                .join(", ")}
              .
            </strong>
          </li>
          <li>A law school is one fact about a lawyer, not a measure of skill; LexRanked&apos;s ranking formula only checks that education is on record, never which school.</li>
        </ul>
      ),
    },
    {
      id: "schools",
      heading: "Which law schools appear most often?",
      answer: `The ${items.length} most common law schools on the records we track, with the number and share of lawyers.`,
      children: (
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Law school</th>
              <th>Lawyers</th>
              <th>Share</th>
            </tr>
          </thead>
          <tbody>
            {items.map((s, i) => (
              <tr key={s.name}>
                <td>{i + 1}</td>
                <td>{s.name}</td>
                <td>{s.count}</td>
                <td>{percent(s.count, sample)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    figuresSection(stats, `Shares are of the ${sample} lawyers whose Florida Bar record names a law school; a lawyer with two schools on record counts for both.`),
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Law schools"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="The law schools most often listed by the board-certified Florida lawyers LexRanked tracks."
      description={DESCRIPTION}
      updated={updated}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: updated,
        sources: ["https://www.floridabar.org/directories/find-mbr/"],
        variables: ["Law school", "Lawyers", "Share of lawyers"],
        spatial: "Florida",
      })}
    />
  );
}
