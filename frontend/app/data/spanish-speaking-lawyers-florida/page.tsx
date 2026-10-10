import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { figuresSection } from "@/components/DataSources";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPageReady, dataPath, MIN_SAMPLE, percent } from "@/lib/content/data-pages";
import { floridaStats } from "@/lib/content/state-stats";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

const PAGE = dataPage("spanish-speaking-lawyers-florida");
const PATH = dataPath(PAGE.slug);
const DESCRIPTION =
  "How many of the Florida lawyers LexRanked tracks list Spanish on their Florida Bar record, city by city and practice area by practice area, and how to find one.";

export async function generateMetadata(): Promise<Metadata> {
  const stats = await floridaStats();
  return buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH, noindex: !dataPageReady(PAGE, stats) });
}

export default async function SpanishSpeakingPage() {
  const stats = await floridaStats();
  if (!dataPageReady(PAGE, stats)) notFound();
  const updated = stats.calculatedAt.slice(0, 10);
  const byShare = <T extends { spanish: number; lawyers: number }>(a: T, b: T) => b.spanish / b.lawyers - a.spanish / a.lawyers || b.spanish - a.spanish;
  const cities = stats.cities.filter((c) => c.lawyers > 0).sort((a, b) => b.spanish - a.spanish || byShare(a, b));
  const areas = [...stats.practiceAreas].sort((a, b) => b.spanish - a.spanish || byShare(a, b));
  // Headlines rest on groups large enough for a share to mean something.
  const highest = cities.filter((c) => c.lawyers >= MIN_SAMPLE).sort(byShare)[0];
  const topArea = areas.filter((a) => a.lawyers >= MIN_SAMPLE).sort(byShare)[0];

  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "How many Florida lawyers speak Spanish?",
      answer: `${stats.spanish} of the ${stats.lawyers} Florida lawyers LexRanked tracks (${percent(stats.spanish, stats.lawyers)}) list Spanish on their Florida Bar record.`,
      children: (
        <ul>
          {highest ? (
            <li>
              <strong>
                {highest.name} has the highest share: {highest.spanish} of {highest.lawyers} ({percent(highest.spanish, highest.lawyers)}).
              </strong>
            </li>
          ) : null}
          {topArea ? (
            <li>
              Among practice areas, {topArea.name} has the highest share: {topArea.spanish} of {topArea.lawyers} (
              {percent(topArea.spanish, topArea.lawyers)}).
            </li>
          ) : null}
          <li>
            The real number is higher: the Bar record lists only the languages a lawyer chose to report, so a lawyer without Spanish on the
            record may still speak it.
          </li>
        </ul>
      ),
    },
    {
      id: "by-city",
      heading: "Which Florida cities have the most Spanish-speaking lawyers?",
      answer: highest
        ? `${cities[0].name} has the most (${cities[0].spanish}) and ${highest.name} the highest share (${percent(highest.spanish, highest.lawyers)}) among the cities we track.`
        : "The table lists every city we track.",
      children: (
        <table>
          <thead>
            <tr>
              <th>City</th>
              <th>Lawyers tracked</th>
              <th>List Spanish</th>
              <th>Share</th>
            </tr>
          </thead>
          <tbody>
            {cities.map((c) => (
              <tr key={c.slug}>
                <td>
                  <Link href={`/cities/${c.slug}/`}>{c.name}</Link>
                </td>
                <td>{c.lawyers}</td>
                <td>{c.spanish}</td>
                <td>{percent(c.spanish, c.lawyers)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "by-area",
      heading: "In which practice areas are Spanish-speaking lawyers most common?",
      answer: topArea ? `${topArea.name}, where ${percent(topArea.spanish, topArea.lawyers)} of the lawyers we track list Spanish.` : "The table lists every practice area.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Practice area</th>
              <th>Lawyers tracked</th>
              <th>List Spanish</th>
              <th>Share</th>
            </tr>
          </thead>
          <tbody>
            {areas.map((a) => (
              <tr key={a.slug}>
                <td>
                  <Link href={`/practice-areas/${a.slug}/`}>{a.name}</Link>
                </td>
                <td>{a.lawyers}</td>
                <td>{a.spanish}</td>
                <td>{percent(a.spanish, a.lawyers)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "how-to-find",
      heading: "How do I find a Spanish-speaking lawyer in Florida?",
      answer: "Open a ranking for your city and practice area: each lawyer's profile lists the languages on their Florida Bar record, and the ranking text says how many speak Spanish.",
      children: (
        <ul>
          <li>
            Start from the <Link href="/rankings/">rankings</Link> or your <Link href="/cities/">city</Link>.
          </li>
          <li>
            Confirm the languages on the lawyer&apos;s own record in{" "}
            <a href="https://www.floridabar.org/directories/find-mbr/" rel="noopener">
              The Florida Bar&apos;s member directory
            </a>
            .
          </li>
          <li>
            <strong>Ask at the first call whether the lawyer handles the whole case in Spanish</strong> or uses an interpreter for some steps;
            court hearings in Florida are held in English, with a court interpreter when needed.
          </li>
        </ul>
      ),
    },
    {
      id: "faq",
      heading: "Frequently asked questions",
      answer: "Short answers about Spanish-speaking lawyers and these figures.",
      children: (
        <>
          <h3>Does a Spanish-speaking lawyer cost more?</h3>
          <p>
            <strong>No; fees depend on the case and the lawyer, not the language.</strong> A bilingual lawyer can save the cost of an
            interpreter for meetings and documents.
          </p>
          <h3>Can I get a court interpreter if my lawyer does not speak Spanish?</h3>
          <p>
            <strong>Usually, yes: Florida courts appoint interpreters in criminal and juvenile cases and in many civil cases.</strong> Meetings
            with your own lawyer are not covered, so ask the lawyer how they handle them.
          </p>
          <h3>Which other languages do Florida lawyers speak?</h3>
          <p>
            <strong>See <Link href="/data/florida-lawyer-languages/">languages spoken by Florida lawyers</Link></strong> for every language
            on the records we track.
          </p>
        </>
      ),
    },
    figuresSection(stats, `A lawyer counts as Spanish-speaking when their Florida Bar record lists Spanish among the languages they speak.`),
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Spanish-speaking lawyers"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="The share of Florida lawyers LexRanked tracks who list Spanish on their Florida Bar record, by city and practice area."
      description={DESCRIPTION}
      updated={updated}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: updated,
        sources: ["https://www.floridabar.org/directories/find-mbr/"],
        variables: ["Lawyers tracked", "Lawyers listing Spanish", "Share of lawyers listing Spanish", "City", "Practice area"],
        spatial: "Florida",
      })}
    />
  );
}
