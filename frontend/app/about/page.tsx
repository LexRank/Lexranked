import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { allLawyers, allRankings, load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 3600;

const PATH = "/about/";
const DESCRIPTION =
  "What LexRanked is, who it is for, how its lawyer rankings are built from official records, how it is funded and what it does not do.";

export const metadata: Metadata = buildMetadata({ title: "About LexRanked", description: DESCRIPTION, path: PATH });

export default async function AboutPage() {
  const [rankings, lawyers] = await Promise.all([load(allRankings), load(() => allLawyers())]);
  const live = rankings.ok ? rankings.data.filter((r) => !r.isThin && !r.isDemo) : [];
  const cities = new Set(live.map((r) => r.location?.city).filter(Boolean));
  const areas = new Set(live.map((r) => r.practiceArea?.slug).filter(Boolean));
  const lawyerCount = lawyers.ok ? lawyers.data.filter((l) => !l.isDemo).length : null;

  const sections: TrustSection[] = [
    {
      id: "what",
      heading: "What is LexRanked?",
      answer:
        "LexRanked is an independent information service that ranks lawyers using facts from official records, such as the state bar, and shows the source of every fact.",
      children: (
        <>
          <p>
            Each ranking covers one practice area in one city, for example personal injury lawyers in Miami. Each lawyer has a profile with
            their license, years in practice, board certifications, education and languages, and <strong>every fact links to the record it
            came from and the date it was checked</strong>.
          </p>
          <p>
            We also publish guides that explain the law and the process behind common legal problems, written from primary sources such as
            statutes and court rules.
          </p>
        </>
      ),
    },
    {
      id: "coverage",
      heading: "Which lawyers and places does LexRanked cover?",
      answer:
        lawyerCount !== null && live.length > 0
          ? `LexRanked currently covers Florida: ${lawyerCount.toLocaleString("en-US")} lawyer profiles and ${live.length} rankings in ${cities.size} cities and ${areas.size} practice areas.`
          : "LexRanked currently covers Florida and adds cities and practice areas as soon as it has enough verified data for them.",
      children: (
        <>
          <p>
            We start a ranking only when <strong>at least five lawyers</strong> in that city and practice area have verified records, and we
            publish it only with complete text for the reader. New cities, practice areas and states are added in that order: data first,
            then the page.
          </p>
          <p>
            See all <Link href="/rankings/">rankings</Link>, <Link href="/cities/">cities</Link> and{" "}
            <Link href="/practice-areas/">practice areas</Link>.
          </p>
        </>
      ),
    },
    {
      id: "how",
      heading: "How are the rankings calculated?",
      answer:
        "Every lawyer gets a LexRank score from 0 to 100, calculated by a published formula from facts with a cited source; the same data always gives the same result.",
      children: (
        <>
          <p>The score combines:</p>
          <ul>
            <li>
              <strong>experience:</strong> years in practice since admission to the bar;
            </li>
            <li>
              <strong>practice relevance:</strong> how closely the lawyer&apos;s practice matches the ranking;
            </li>
            <li>
              <strong>reputation and credentials:</strong> recorded awards and board certifications, such as Florida Bar board
              certification;
            </li>
            <li>
              <strong>local relevance:</strong> an office in the ranked city;
            </li>
            <li>
              <strong>data quality:</strong> how complete and well-sourced the profile is.
            </li>
          </ul>
          <p>
            <strong>Client reviews are shown on profiles but are not part of the score</strong>, and missing data scores zero rather than being
            estimated. The full formula, weights and version history are on <Link href="/methodology/">how rankings work</Link>.
          </p>
        </>
      ),
    },
    {
      id: "sources",
      heading: "Where does the data come from?",
      answer:
        "From official and public records: for Florida lawyers, mainly The Florida Bar's member directory, which shows license status, admission date, board certifications and languages.",
      children: (
        <p>
          Each fact keeps its source, the date it was observed and whether it was verified. When two sources disagree, the fact is marked as a
          conflict and is not used in the score until it is resolved. <Link href="/verified/">What “verified” means</Link> explains the
          checks behind the verified badge.
        </p>
      ),
    },
    {
      id: "funding",
      heading: "How is LexRanked funded?",
      answer: "LexRanked is funded in part by labelled advertising from lawyers and law firms, and payment never changes a score, a position or who is ranked.",
      children: (
        <p>
          Lawyers can claim their profile for free to request corrections backed by a public source. Paid placements are always labelled
          “Paid”, shown outside the ranked list and excluded from the ranking code. The rules are in our{" "}
          <Link href="/advertising/">advertising policy</Link>.
        </p>
      ),
    },
    {
      id: "not",
      heading: "What does LexRanked not do?",
      answer: "LexRanked is not a law firm, does not give legal advice and is not a lawyer referral service.",
      children: (
        <ul>
          <li>We do not recommend a lawyer for your case and we do not take a fee when you hire one.</li>
          <li>Using the site does not create a lawyer-client relationship with LexRanked or with any listed lawyer.</li>
          <li>
            A ranking compares documented facts; it is not a guarantee of results. Read the full <Link href="/disclaimer/">legal disclaimer</Link>.
          </li>
        </ul>
      ),
    },
    {
      id: "editorial",
      heading: "Who writes the content?",
      answer:
        "The LexRanked editorial team, following a written editorial standard: complete answers, primary sources, facts checked before publication and pages updated when the law or data change.",
      children: (
        <p>
          Ranking pages are written from each ranking&apos;s own data and verified legal facts, and they update when the ranking changes. How
          we research, check and correct content, including how we use AI tools, is set out in our{" "}
          <Link href="/editorial-policy/">editorial policy</Link>.
        </p>
      ),
    },
    {
      id: "contact",
      heading: "How can I contact LexRanked?",
      answer: "Use the contact form: it reaches the editors, who reply by email.",
      children: (
        <p>
          To report an error on a profile or ranking, include the page address and a link to the public source with the correct information.{" "}
          <Link href="/contact/">Contact LexRanked</Link>.
        </p>
      ),
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="About"
      title="About LexRanked"
      lead="Lawyer rankings built from official records, with the source of every fact shown."
      description={DESCRIPTION}
      updated="2026-10-05"
      sections={sections}
    />
  );
}
