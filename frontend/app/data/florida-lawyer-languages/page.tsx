import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { figuresSection } from "@/components/DataSources";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPageReady, dataPath, percent } from "@/lib/content/data-pages";
import { floridaStats } from "@/lib/content/state-stats";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

const PAGE = dataPage("florida-lawyer-languages");
const PATH = dataPath(PAGE.slug);
const DESCRIPTION =
  "Every language other than English listed on the Florida Bar records of the lawyers LexRanked tracks, how many lawyers list each one, and what the records do not show.";

export async function generateMetadata(): Promise<Metadata> {
  const stats = await floridaStats();
  return buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH, noindex: !dataPageReady(PAGE, stats) });
}

export default async function LanguagesPage() {
  const stats = await floridaStats();
  if (!dataPageReady(PAGE, stats)) notFound();
  const updated = stats.calculatedAt.slice(0, 10);
  const items = stats.languages.items.filter((l) => l.name !== "English");
  const [first, second, third] = items;
  const single = items.filter((l) => l.count === 1).map((l) => l.name);

  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "Which languages do Florida lawyers speak?",
      answer: `The lawyers LexRanked tracks list ${items.length} languages besides English; ${first ? `${first.name} is by far the most common (${first.count} lawyers)` : "none is common"}.`,
      children: (
        <ul>
          {second ? (
            <li>
              <strong>
                After {first.name} come {second.name} ({second.count}){third ? ` and ${third.name} (${third.count})` : ""}.
              </strong>
            </li>
          ) : null}
          <li>
            Only {stats.languages.sample} of the {stats.lawyers} lawyers ({percent(stats.languages.sample, stats.lawyers)}) list any language on
            their record, so these counts are a minimum, not a census.
          </li>
          {single.length > 0 ? <li>Listed by a single lawyer: {single.join(", ")}.</li> : null}
        </ul>
      ),
    },
    {
      id: "all-languages",
      heading: "How many lawyers list each language?",
      answer: "The table lists every language other than English, most common first.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Language</th>
              <th>Lawyers</th>
            </tr>
          </thead>
          <tbody>
            {items.map((l) => (
              <tr key={l.name}>
                <td>{l.name}</td>
                <td>{l.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "limits",
      heading: "Why might a lawyer who speaks a language not be counted?",
      answer: "Because the Bar record shows only the languages a lawyer chose to report; many bilingual lawyers leave the field empty.",
      children: (
        <ul>
          <li>Ask the lawyer&apos;s office directly; many firms also have bilingual staff who are not lawyers.</li>
          <li>
            For Spanish, see <Link href="/data/spanish-speaking-lawyers-florida/">Spanish-speaking lawyers by city</Link>.
          </li>
        </ul>
      ),
    },
    figuresSection(stats, `A lawyer counts once for each language listed on their Florida Bar record; English is left out because every Florida lawyer practises in it.`),
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Languages"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="Every language other than English listed on the Florida Bar records of the lawyers LexRanked tracks."
      description={DESCRIPTION}
      updated={updated}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: updated,
        sources: ["https://www.floridabar.org/directories/find-mbr/"],
        variables: ["Language", "Lawyers listing the language"],
        spatial: "Florida",
      })}
    />
  );
}
