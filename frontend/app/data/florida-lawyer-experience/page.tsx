import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { figuresSection } from "@/components/DataSources";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPageReady, dataPath, MIN_SAMPLE, percent } from "@/lib/content/data-pages";
import { floridaStats } from "@/lib/content/state-stats";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import type { SpreadDto } from "@/types/api";

export const revalidate = 3600;

const PAGE = dataPage("florida-lawyer-experience");
const PATH = dataPath(PAGE.slug);
const DESCRIPTION =
  "How many years Florida's board-certified lawyers have practised: median and range by practice area and city, and how many have practised 30 years or more.";

export async function generateMetadata(): Promise<Metadata> {
  const stats = await floridaStats().catch(() => null);
  return buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH, noindex: !dataPageReady(PAGE, stats) });
}

function years(n: number | null): string {
  return n === null ? "—" : `${Number.isInteger(n) ? n : n.toFixed(1)}`;
}

function SpreadCells({ s }: { s: SpreadDto }) {
  return (
    <>
      <td>{years(s.median)}</td>
      <td>
        {years(s.min)} to {years(s.max)}
      </td>
      <td>{s.sample}</td>
    </>
  );
}

export default async function ExperiencePage() {
  const stats = await floridaStats();
  if (!dataPageReady(PAGE, stats)) notFound();
  const updated = stats.calculatedAt.slice(0, 10);
  const e = stats.experience;
  const over30 = e.buckets.slice(3).reduce((sum, b) => sum + b.count, 0);
  const areas = stats.practiceAreas.filter((a) => a.experience.sample > 0).sort((a, b) => (b.experience.median ?? 0) - (a.experience.median ?? 0));
  const cities = stats.cities.filter((c) => c.experience.sample > 0).sort((a, b) => (b.experience.median ?? 0) - (a.experience.median ?? 0));
  const large = areas.filter((a) => a.experience.sample >= MIN_SAMPLE);
  const oldest = large[0];
  const youngest = large[large.length - 1];
  const topCity = cities.find((c) => c.experience.sample >= MIN_SAMPLE);
  const tiedNames = (median: number | null | undefined) =>
    large
      .filter((a) => a.experience.median === median)
      .map((a) => a.name)
      .join(" and ");

  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "How experienced are Florida's board-certified lawyers?",
      answer: `The median lawyer LexRanked tracks has practised ${years(e.median)} years; ${over30} of ${e.sample} (${percent(over30, e.sample)}) have practised 30 years or more.`,
      children: (
        <ul>
          <li>
            The range runs from {years(e.min)} to {years(e.max)} years. Florida board certification itself requires at least five years of
            practice.
          </li>
          {oldest && youngest && oldest !== youngest ? (
            <li>
              <strong>
                {tiedNames(oldest.experience.median)} lawyers have the most experience (median {years(oldest.experience.median)} years);{" "}
                {tiedNames(youngest.experience.median)} the least (median {years(youngest.experience.median)}).
              </strong>
            </li>
          ) : null}
          <li>Years are counted from admission to The Florida Bar, so practice in another state before that is not included.</li>
        </ul>
      ),
    },
    {
      id: "distribution",
      heading: "How many lawyers fall into each band of experience?",
      answer: "Most of the lawyers we track have practised for decades; the table counts them by band.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Years in practice</th>
              <th>Lawyers</th>
              <th>Share</th>
            </tr>
          </thead>
          <tbody>
            {e.buckets.map((b) => (
              <tr key={b.label}>
                <td>{b.label}</td>
                <td>{b.count}</td>
                <td>{percent(b.count, e.sample)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "by-area",
      heading: "Which practice areas have the most experienced lawyers?",
      answer: oldest
        ? `${tiedNames(oldest.experience.median)}, with a median of ${years(oldest.experience.median)} years in practice, among areas with at least ${MIN_SAMPLE} lawyers.`
        : "The table lists every practice area.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Practice area</th>
              <th>Median years</th>
              <th>Range</th>
              <th>Lawyers</th>
            </tr>
          </thead>
          <tbody>
            {areas.map((a) => (
              <tr key={a.slug}>
                <td>
                  <Link href={`/practice-areas/${a.slug}/`}>{a.name}</Link>
                </td>
                <SpreadCells s={a.experience} />
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "by-city",
      heading: "How does experience differ by city?",
      answer: topCity
        ? `${topCity.name} has the highest median (${years(topCity.experience.median)} years) among the cities where we track at least ${MIN_SAMPLE} lawyers.`
        : "The table lists every city.",
      children: (
        <table>
          <thead>
            <tr>
              <th>City</th>
              <th>Median years</th>
              <th>Range</th>
              <th>Lawyers</th>
            </tr>
          </thead>
          <tbody>
            {cities.map((c) => (
              <tr key={c.slug}>
                <td>
                  <Link href={`/cities/${c.slug}/`}>{c.name}</Link>
                </td>
                <SpreadCells s={c.experience} />
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "faq",
      heading: "Frequently asked questions",
      answer: "Short answers about experience and choosing a lawyer.",
      children: (
        <>
          <h3>Is more experience always better?</h3>
          <p>
            <strong>Not by itself.</strong> Experience in your type of case matters more than total years, and a senior lawyer may hand much of
            the work to associates. Ask who will handle your case day to day.
          </p>
          <h3>How does LexRanked use experience in rankings?</h3>
          <p>
            <strong>Years in practice is one documented fact in the score</strong>, weighed with certification and other facts as described
            in <Link href="/methodology/">how rankings work</Link>.
          </p>
        </>
      ),
    },
    figuresSection(stats, `Years in practice come from the admission date on each lawyer's Florida Bar record; ${e.sample} of ${stats.lawyers} records have one.`),
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Years in practice"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="Median and range of years in practice for the board-certified Florida lawyers LexRanked tracks, by practice area and city."
      description={DESCRIPTION}
      updated={updated}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: updated,
        sources: ["https://www.floridabar.org/directories/find-mbr/"],
        variables: ["Years in practice (median)", "Years in practice (range)", "Practice area", "City"],
        spatial: "Florida",
      })}
    />
  );
}
