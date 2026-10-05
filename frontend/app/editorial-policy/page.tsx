import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { buildMetadata } from "@/lib/seo/metadata";

const PATH = "/editorial-policy/";
const DESCRIPTION =
  "How LexRanked researches, writes, checks, updates and corrects its guides, rankings and profiles, which sources it uses and how it uses AI tools.";

export const metadata: Metadata = buildMetadata({ title: "Editorial Policy", description: DESCRIPTION, path: PATH });

const STANDARD: Array<[string, string]> = [
  ["Complete", "A page answers its topic fully, including the questions a reader is likely to ask next, so they do not need another source."],
  ["Answer first", "Every heading is followed by a direct answer in one or two sentences, highlighted in bold; the detail comes after."],
  ["Easy to use", "Lists, tables, steps and checklists are used wherever they make an answer faster to find or compare."],
  ["Researched and true", "Every legal rule, deadline, number, fee or statistic comes from a primary or official source that is linked on the page."],
  ["Honest", "No claim goes beyond what the sources and data show; a page never says “best” without the data behind it."],
  ["Local", "Rules are stated for the state they apply to (for example Florida), with the statute or court rule number."],
  ["Something new", "Each page adds at least one true, useful thing other pages on the topic do not have, such as a comparison table or LexRanked's own verified data."],
  ["Current", "Each page shows when it was last reviewed and is updated when the law, the data or the ranking changes."],
];

const SOURCES: Array<[string, string]> = [
  ["Lawyer license, status, admission date, certifications, languages", "The state bar's official member directory (The Florida Bar for Florida)"],
  ["Laws and deadlines", "The state's official statutes (for Florida, the Florida Senate's statutes site) and court rules"],
  ["Court structure", "The state courts' official website"],
  ["Statistics", "Government agencies, for example the Florida Department of Highway Safety and Motor Vehicles for crash data"],
  ["Lawyer fees and ethics rules", "The state bar's Rules of Professional Conduct"],
];

export default function EditorialPolicyPage() {
  const sections: TrustSection[] = [
    {
      id: "standard",
      heading: "What standard does every LexRanked page follow?",
      answer: "Every guide, ranking and profile text must be complete, answer first, sourced, honest and current; a page that does not meet the standard is not published.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Rule</th>
              <th>What it means</th>
            </tr>
          </thead>
          <tbody>
            {STANDARD.map(([rule, meaning]) => (
              <tr key={rule}>
                <td>{rule}</td>
                <td>{meaning}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "sources",
      heading: "Which sources does LexRanked use?",
      answer: "Primary and official sources first: state bars, statutes, court rules, courts and government agencies; secondary sources are never the only basis for a fact.",
      children: (
        <>
          <table>
            <thead>
              <tr>
                <th>Information</th>
                <th>Source we use</th>
              </tr>
            </thead>
            <tbody>
              {SOURCES.map(([what, source]) => (
                <tr key={what}>
                  <td>{what}</td>
                  <td>{source}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p>
            <strong>Sources are linked on the page where the fact appears</strong>, usually in a Sources section at the end of a guide or
            ranking, and on each lawyer profile next to the fact.
          </p>
        </>
      ),
    },
    {
      id: "rankings",
      heading: "How is the text on ranking pages written?",
      answer:
        "Ranking pages are written from the ranking's own data and a set of verified legal facts for the state, and the text updates automatically when the ranking changes.",
      children: (
        <ul>
          <li>
            Figures such as how many ranked lawyers are board certified, their years in practice and the languages they speak are{" "}
            <strong>calculated from the profiles in that ranking</strong>, never estimated.
          </li>
          <li>A statement such as “all are board certified” appears only when it is true of every ranked lawyer.</li>
          <li>
            A ranking is published only together with this complete text; without verified facts for its city or practice area, it stays
            unpublished.
          </li>
          <li>
            The order of lawyers comes only from the published <Link href="/methodology/">methodology</Link>; editors cannot move a lawyer up or
            down.
          </li>
        </ul>
      ),
    },
    {
      id: "ai",
      heading: "Does LexRanked use AI?",
      answer: "Yes, as a research and drafting tool; no AI-written text is published automatically, and every fact in it must be checked against a primary source first.",
      children: (
        <ul>
          <li>Guides may be researched and drafted with AI tools; the statutes, rules and agency pages they rely on are read and linked.</li>
          <li>Text drafted automatically from LexRanked data goes through automated checks and stays a draft until an editor applies it.</li>
          <li>Ranking scores never use AI: they come from a fixed, published formula.</li>
          <li>Images in guides are illustrations of the LexRanked owl and never show real people.</li>
        </ul>
      ),
    },
    {
      id: "independence",
      heading: "Can lawyers pay to change what LexRanked writes?",
      answer: "No. Payment never changes a ranking, a score, a profile's facts or the editorial text.",
      children: (
        <p>
          Lawyers can claim their profile for free and ask for corrections, but each correction must be backed by a public source. Paid
          placements are labelled and kept outside the rankings, as described in the <Link href="/advertising/">advertising policy</Link>.
        </p>
      ),
    },
    {
      id: "updates",
      heading: "How often is content updated?",
      answer: "Rankings and their text update whenever the underlying data changes; guides are reviewed at least once a year and as soon as a law they describe changes.",
      children: (
        <p>
          Each page shows its last review date. Lawyer data is re-checked against the official source on a schedule, and each profile shows
          when its facts were last verified.
        </p>
      ),
    },
    {
      id: "corrections",
      heading: "How do I report an error?",
      answer: "Send the page address and a link to the source with the correct information through the contact form; verified errors are corrected and the page date is updated.",
      children: (
        <p>
          Lawyers can also claim their profile to request corrections directly. <Link href="/contact/?topic=correction">Report an error</Link>.
        </p>
      ),
    },
    {
      id: "advice",
      heading: "Is LexRanked content legal advice?",
      answer: "No. Our guides explain the law in general; for advice on your situation, speak to a lawyer licensed in your state.",
      children: (
        <p>
          Deadlines and rules can change and can depend on details of your case. See the <Link href="/disclaimer/">legal disclaimer</Link>.
        </p>
      ),
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Editorial policy"
      title="Editorial policy"
      lead="How we research, write, check and correct every guide, ranking and profile."
      description={DESCRIPTION}
      updated="2026-10-05"
      sections={sections}
    />
  );
}
