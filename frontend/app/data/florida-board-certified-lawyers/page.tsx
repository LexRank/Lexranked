import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { figuresSection } from "@/components/DataSources";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPageReady, dataPath } from "@/lib/content/data-pages";
import { floridaStats } from "@/lib/content/state-stats";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

const PAGE = dataPage("florida-board-certified-lawyers");
const PATH = dataPath(PAGE.slug);
const DESCRIPTION =
  "How many board-certified lawyers LexRanked tracks in each Florida city and practice area, which Florida Bar certifications they hold and how many hold two or more.";

export async function generateMetadata(): Promise<Metadata> {
  const stats = await floridaStats();
  return buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH, noindex: !dataPageReady(PAGE, stats) });
}

export default async function BoardCertifiedPage() {
  const stats = await floridaStats();
  if (!dataPageReady(PAGE, stats)) notFound();
  const updated = stats.calculatedAt.slice(0, 10);
  const top = stats.cities[0];
  const certs = stats.certifications;
  const topCert = certs[0];

  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "How many board-certified lawyers does LexRanked track in Florida?",
      answer: `${stats.certified} board-certified lawyers in ${stats.cities.length} Florida cities; ${stats.multiCertified} of them hold two or more certifications.`,
      children: (
        <ul>
          {top ? (
            <li>
              <strong>
                {top.name} has the most ({top.certified}), followed by{" "}
                {stats.cities
                  .slice(1, 3)
                  .map((c) => `${c.name} (${c.certified})`)
                  .join(" and ")}
                .
              </strong>
            </li>
          ) : null}
          {topCert ? (
            <li>
              The most common certification is <strong>{topCert.name}</strong> ({topCert.count} lawyers).
            </li>
          ) : null}
          <li>
            Board certification is The Florida Bar&apos;s highest recognition of a lawyer&apos;s competence in one area of law; only{" "}
            <a href="https://www.floridabar.org/the-florida-bar-news/board-certification-continues-to-set-florida-lawyers-apart/" rel="noopener">
              4.9% of Florida Bar members hold it
            </a>{" "}
            (The Florida Bar News, July 2026).
          </li>
        </ul>
      ),
    },
    {
      id: "what-it-means",
      heading: "What does board certified mean in Florida?",
      answer:
        "The Florida Bar's Board of Legal Specialization and Education certified the lawyer in one area after checking years of practice, substantial involvement in that area, peer reviews, continuing education and a written exam.",
      children: (
        <ul>
          <li>Certification requires at least five years of practice and has to be renewed every five years.</li>
          <li>
            Check any certification in{" "}
            <a href="https://www.floridabar.org/directories/find-mbr/" rel="noopener">
              the Bar&apos;s member directory
            </a>
            ; requirements per area are on{" "}
            <a href="https://www.floridabar.org/about/cert/" rel="noopener">
              the Bar&apos;s certification pages
            </a>
            .
          </li>
        </ul>
      ),
    },
    {
      id: "by-city",
      heading: "How many board-certified lawyers are there in each city?",
      answer: `The table counts the board-certified lawyers LexRanked tracks in each city and their largest practice areas; ${top ? `${top.name} leads` : "the largest cities lead"}.`,
      children: (
        <table>
          <thead>
            <tr>
              <th>City</th>
              <th>Board-certified lawyers</th>
              <th>Largest practice areas</th>
            </tr>
          </thead>
          <tbody>
            {stats.cities.map((c) => (
              <tr key={c.slug}>
                <td>
                  <Link href={`/cities/${c.slug}/`}>{c.name}</Link>
                </td>
                <td>{c.certified}</td>
                <td>
                  {c.areas
                    .slice(0, 3)
                    .map((a) => `${a.name} (${a.count})`)
                    .join(", ")}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "by-area",
      heading: "Which practice areas have the most board-certified lawyers?",
      answer: stats.practiceAreas[0]
        ? `${stats.practiceAreas[0].name}, with ${stats.practiceAreas[0].certified} certified lawyers among those we track in ${stats.practiceAreas[0].cities} cities.`
        : "The table lists every practice area with tracked lawyers.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Practice area</th>
              <th>Board-certified lawyers</th>
              <th>Cities</th>
            </tr>
          </thead>
          <tbody>
            {stats.practiceAreas.map((a) => (
              <tr key={a.slug}>
                <td>
                  <Link href={`/practice-areas/${a.slug}/`}>{a.name}</Link>
                </td>
                <td>{a.certified}</td>
                <td>{a.cities}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "certifications",
      heading: "Which certifications do these lawyers hold?",
      answer: `${certs.length} different Florida Bar certifications appear, led by ${topCert ? topCert.name : "civil trial law"}; a lawyer with two certifications is counted under both.`,
      children: (
        <table>
          <thead>
            <tr>
              <th>Certification</th>
              <th>Lawyers</th>
            </tr>
          </thead>
          <tbody>
            {certs.map((c) => (
              <tr key={c.name}>
                <td>{c.name}</td>
                <td>{c.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "faq",
      heading: "Frequently asked questions",
      answer: "Short answers about board certification and these figures.",
      children: (
        <>
          <h3>Is a board-certified lawyer always better?</h3>
          <p>
            <strong>Not always, but certification is independent proof of experience and peer standing in one area.</strong> Many capable
            lawyers never apply. Our <Link href="/methodology/">ranking formula</Link> weighs certification together with experience and other
            documented facts.
          </p>
          <h3>Why do some cities have so few lawyers here?</h3>
          <p>
            <strong>Because we add cities and practice areas step by step.</strong> A low number means we have not finished that city, not that
            it has few good lawyers.
          </p>
          <h3>How often are these numbers updated?</h3>
          <p>
            <strong>Automatically, whenever scores are recalculated</strong>; the date of the current figures is shown below.
          </p>
        </>
      ),
    },
    figuresSection(stats, `Practice areas follow each profile's areas of practice; a lawyer can appear in more than one.`),
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Board-certified lawyers"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="How many board-certified lawyers LexRanked tracks in each Florida city and practice area, and which certifications they hold."
      description={DESCRIPTION}
      updated={updated}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: updated,
        sources: ["https://www.floridabar.org/directories/find-mbr/"],
        variables: ["Lawyers tracked", "Board-certified lawyers", "Florida Bar certification", "City", "Practice area"],
        spatial: "Florida",
      })}
    />
  );
}
