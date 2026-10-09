import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPath, flStatute } from "@/lib/content/data-pages";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

const PAGE = dataPage("florida-statutes-of-limitations");
const PATH = dataPath(PAGE.slug);
const UPDATED = "2026-10-09";
const DESCRIPTION =
  "Every Florida deadline to sue or prosecute in one table, with the exact statute subsection, the 2023 cut to two years for negligence, and the notice deadlines that come first.";

export const metadata: Metadata = buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH });

type Row = [what: string, deadline: string, startsWhen: string, section: string, cite: string];

/** A statute citation linked to the official text. */
function S({ section, cite }: { section: string; cite: string }) {
  return (
    <a href={flStatute(section)} rel="noopener">
      {cite}
    </a>
  );
}

function DeadlineTable({ rows }: { rows: Row[] }) {
  return (
    <table>
      <thead>
        <tr>
          <th>Claim</th>
          <th>Deadline</th>
          <th>Clock starts</th>
          <th>Statute</th>
        </tr>
      </thead>
      <tbody>
        {rows.map(([what, deadline, starts, section, cite]) => (
          <tr key={what}>
            <td>{what}</td>
            <td>
              <strong>{deadline}</strong>
            </td>
            <td>{starts}</td>
            <td>
              <S section={section} cite={cite} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

const INJURY: Row[] = [
  ["Negligence: car crashes, slip and fall, most personal injury", "2 years", "the injury (accrual)", "95.11", "s. 95.11(5)(a)"],
  ["Wrongful death", "2 years", "the death", "95.11", "s. 95.11(5)(e)"],
  ["Medical malpractice", "2 years, never more than 4 (7 if fraud or concealment hid the injury)", "the incident, or when it was or should have been discovered", "95.11", "s. 95.11(5)(c)"],
  ["Professional malpractice other than medical (lawyers, accountants)", "2 years", "when it was or should have been discovered", "95.11", "s. 95.11(5)(b)"],
  ["Libel or slander", "2 years", "publication", "95.11", "s. 95.11(5)(h)"],
  ["Assault, battery, false imprisonment and other intentional torts", "4 years", "the act", "95.11", "s. 95.11(3)(n)"],
  ["Injury from a defective product", "4 years; no claim 12 years after delivery for products with a useful life of 10 years or less", "when the facts were or should have been discovered", "95.031", "s. 95.11(3)(d); s. 95.031(2)(b)"],
  ["Claim against the state, a county or a city (negligence)", "written notice within 3 years, lawsuit within 4 years", "the injury", "768.28", "s. 768.28(6), (14)"],
];

const PROPERTY: Row[] = [
  ["Written contract", "5 years", "the breach", "95.11", "s. 95.11(2)(b)"],
  ["Oral contract, store account, sale of goods", "4 years", "the breach", "95.11", "s. 95.11(3)(j)"],
  ["Breach of a property insurance contract", "5 years", "the date of loss", "95.11", "s. 95.11(2)(e)"],
  ["Mortgage foreclosure", "5 years", "the default", "95.11", "s. 95.11(2)(c)"],
  ["Deficiency after foreclosure of a 1–4 family home", "1 year", "the day after the clerk's certificate or a deed in lieu", "95.11", "s. 95.11(6)(g)"],
  ["Construction defects", "4 years; never more than 7", "the certificate of occupancy or completion (latent defects: discovery)", "95.11", "s. 95.11(3)(b)"],
  ["Fraud", "4 years; never more than 12", "when the fraud was or should have been discovered", "95.031", "s. 95.11(3)(i); s. 95.031(2)(a)"],
  ["Trespass on real property; damage to personal property", "4 years", "the act", "95.11", "s. 95.11(3)(f), (g)"],
  ["Specific performance of a contract", "1 year", "the breach", "95.11", "s. 95.11(6)(a)"],
  ["Enforcing a Florida court judgment", "20 years", "the judgment", "95.11", "s. 95.11(1)"],
  ["Enforcing a judgment from another state, a federal court or a foreign country", "5 years", "the judgment", "95.11", "s. 95.11(2)(a)"],
];

const WORK: Row[] = [
  ["Unpaid wages or overtime", "2 years", "each missed payment", "95.11", "s. 95.11(5)(d)"],
  ["Florida minimum wage violation", "4 years (5 if willful)", "each missed payment", "95.11", "s. 95.11(3)(p), (2)(d)"],
  ["Discrimination under the Florida Civil Rights Act", "complaint to the Florida Commission on Human Relations within 365 days", "the discriminatory act", "760.11", "s. 760.11(1)"],
  ["Workers' compensation benefits", "petition within 2 years (each benefit payment extends it by 1 year from that payment)", "when you knew or should have known the injury came from work", "440.19", "s. 440.19(1), (2)"],
  ["Paternity", "4 years", "the child's 18th birthday", "95.11", "s. 95.11(3)(a)"],
  ["Claim against a deceased person's estate", "3 months after the notice to creditors is first published; never more than 2 years after the death", "publication of the notice", "733.702", "s. 733.702; s. 733.710"],
];

const CRIMINAL: Row[] = [
  ["Capital or life felony, or a felony that caused a death", "no limit", "—", "775.15", "s. 775.15(1)"],
  ["First-degree felony", "4 years", "the day after the offense", "775.15", "s. 775.15(2)(a)"],
  ["Any other felony", "3 years", "the day after the offense", "775.15", "s. 775.15(2)(b)"],
  ["First-degree misdemeanor", "2 years", "the day after the offense", "775.15", "s. 775.15(2)(c)"],
  ["Second-degree misdemeanor or noncriminal violation", "1 year", "the day after the offense", "775.15", "s. 775.15(2)(d)"],
];

export default function StatutesOfLimitationsPage() {
  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "What are the Florida deadlines in short?",
      answer: "Most injury claims: 2 years. Written contracts: 5 years. Oral contracts, fraud and intentional torts: 4 years. Felonies: 3 or 4 years, none for the most serious.",
      children: (
        <ul>
          <li>
            <strong>Negligence fell from 4 years to 2</strong> for injuries after March 24, 2023 (chapter 2023-15, Laws of Florida).
          </li>
          <li>
            <strong>Several deadlines come before the lawsuit deadline</strong>: 30 days to tell your employer about a work injury, 1 year to tell
            your insurer about property damage, 365 days for a discrimination complaint.
          </li>
          <li>A missed deadline usually ends the claim for good; only the reasons listed in s. 95.051 (and a few in the probate and guardianship codes) pause the clock.</li>
          <li>Each row below links to the official statute text on the Florida Senate website.</li>
        </ul>
      ),
    },
    {
      id: "injury",
      heading: "How long do you have to sue for an injury or a death in Florida?",
      answer: "Two years for negligence, wrongful death and medical malpractice; four years for intentional harm such as battery and for injuries from defective products.",
      children: <DeadlineTable rows={INJURY} />,
    },
    {
      id: "2023-change",
      heading: "Which negligence claims still have 4 years?",
      answer: "Only those for injuries on or before March 24, 2023; the 2-year limit applies to causes of action accruing after that date, so the last 4-year deadlines run out on March 24, 2027.",
      children: (
        <p>
          Section 28 of chapter 2023-15 says the change to s. 95.11 applies to causes of action accruing after the act took effect, and the act
          took effect when the Governor signed it on March 24, 2023. <strong>An injury on March 20, 2023 can still be sued for until March 20, 2027;
          an injury on April 1, 2023 had to be sued on by April 1, 2025.</strong>
        </p>
      ),
    },
    {
      id: "property",
      heading: "How long do you have to sue over a contract, property or money?",
      answer: "Five years for a written contract, an insurance contract or a mortgage foreclosure; four years for an oral contract, fraud or property damage; one year for specific performance.",
      children: <DeadlineTable rows={PROPERTY} />,
    },
    {
      id: "work-family",
      heading: "What are the deadlines for work, family and estate claims?",
      answer: "Two years for unpaid wages and for a workers' compensation petition, 365 days for a discrimination complaint, and as little as 3 months for a claim against an estate.",
      children: <DeadlineTable rows={WORK} />,
    },
    {
      id: "notice",
      heading: "Which deadlines come before the lawsuit deadline?",
      answer: "Notice deadlines: miss one and the claim can fail even though the lawsuit deadline is years away.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Notice</th>
              <th>Deadline</th>
              <th>Statute</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Tell your employer about a work injury</td>
              <td>
                <strong>30 days</strong> after the injury or its first sign
              </td>
              <td>
                <S section="440.185" cite="s. 440.185(1)" />
              </td>
            </tr>
            <tr>
              <td>Notify your insurer of a property claim (new or reopened)</td>
              <td>
                <strong>1 year</strong> after the date of loss; supplemental claims 18 months. For a hurricane, the date of loss is landfall.
              </td>
              <td>
                <S section="627.70132" cite="s. 627.70132(2), (3)" />
              </td>
            </tr>
            <tr>
              <td>Present a claim to a government agency (and, for state agencies, the Department of Financial Services)</td>
              <td>
                <strong>3 years</strong> (2 for wrongful death)
              </td>
              <td>
                <S section="768.28" cite="s. 768.28(6)" />
              </td>
            </tr>
            <tr>
              <td>Medical malpractice notice of intent</td>
              <td>
                within the 2-year limit; then <strong>no lawsuit for 90 days</strong> while the clock is paused
              </td>
              <td>
                <S section="766.106" cite="s. 766.106(3), (4)" />
              </td>
            </tr>
            <tr>
              <td>Discrimination complaint to the Florida Commission on Human Relations</td>
              <td>
                <strong>365 days</strong>
              </td>
              <td>
                <S section="760.11" cite="s. 760.11(1)" />
              </td>
            </tr>
          </tbody>
        </table>
      ),
    },
    {
      id: "criminal",
      heading: "How long does Florida have to charge a crime?",
      answer: "Four years for a first-degree felony, three for other felonies, two for a first-degree misdemeanor and one for a second-degree misdemeanor; there is no limit for capital and life felonies or a felony that caused a death.",
      children: (
        <>
          <DeadlineTable rows={CRIMINAL} />
          <p>Section 775.15 also extends or removes these limits for some offenses, mostly sexual offenses and crimes against children.</p>
        </>
      ),
    },
    {
      id: "clock",
      heading: "When does the clock start, and can it stop?",
      answer: "It starts when the last element of the claim occurs, and only the reasons listed in section 95.051, the Probate Code or the Guardianship Law can pause it.",
      children: (
        <ul>
          <li>
            <strong>Start:</strong> a cause of action accrues when its last element occurs (<S section="95.031" cite="s. 95.031(1)" />). For an
            injury that is usually the day it happens; some claims run from discovery, as the tables say.
          </li>
          <li>
            <strong>Pause:</strong> s. 95.051 lists the only reasons, such as the defendant&apos;s absence from Florida or a minor having no parent
            or guardian to sue for them; &quot;a disability or other reason does not toll&quot; the deadline otherwise (
            <S section="95.051" cite="s. 95.051(2)" />
            ).
          </li>
          <li>
            <strong>Servicemembers</strong> on active duty are protected by the Servicemembers Civil Relief Act (
            <S section="95.11" cite="s. 95.11(13)" />
            ).
          </li>
        </ul>
      ),
    },
    {
      id: "faq",
      heading: "Frequently asked questions",
      answer: "Short answers to the questions readers ask most about Florida deadlines.",
      children: (
        <>
          <h3>Is the deadline for a car accident in Florida 2 or 4 years?</h3>
          <p>
            <strong>2 years for a crash after March 24, 2023; 4 years for one on or before that date.</strong> Property damage to your car
            alone is also negligence, so the same rule applies.
          </p>
          <h3>Do I have to file the lawsuit or just contact a lawyer before the deadline?</h3>
          <p>
            <strong>The lawsuit must be filed in court before the deadline.</strong> Calling a lawyer or an insurer does not stop the clock;
            only a filing, or one of the s. 95.051 reasons, does.
          </p>
          <h3>Does the deadline count from the accident or from the day I found out?</h3>
          <p>
            <strong>From the accident for most injuries.</strong> Discovery counts only where the statute says so: medical and professional
            malpractice, fraud, product injuries and latent construction defects.
          </p>
          <h3>What if the injured person is a child?</h3>
          <p>
            <strong>The deadline still runs if the child has a parent or guardian who can sue.</strong> It pauses only when no parent or
            guardian exists or their interest conflicts with the child&apos;s (s. 95.051(1)(i)). In medical malpractice, the 4-year cap does
            not bar a claim brought before the child&apos;s eighth birthday.
          </p>
          <h3>Are federal claims covered here?</h3>
          <p>
            <strong>No.</strong> Federal claims, such as an EEOC charge or a claim against the U.S. government, have their own deadlines set
            by federal law.
          </p>
          <h3>Is this table legal advice?</h3>
          <p>
            <strong>No.</strong> It summarises the statutes; how a deadline applies depends on the facts. See our{" "}
            <Link href="/disclaimer/">legal disclaimer</Link> and speak to a lawyer well before any deadline.
          </p>
        </>
      ),
    },
    {
      id: "sources",
      heading: "Sources",
      answer: "The Florida Statutes (2025 edition) and chapter 2023-15, Laws of Florida, read on October 9, 2026.",
      children: (
        <ul>
          {[
            ["95.11", "s. 95.11 Limitations other than for the recovery of real property"],
            ["95.031", "s. 95.031 Computation of time"],
            ["95.051", "s. 95.051 When limitations tolled"],
            ["768.28", "s. 768.28 Waiver of sovereign immunity in tort actions"],
            ["440.19", "s. 440.19 Time bars to filing petitions for benefits"],
            ["760.11", "s. 760.11 Administrative and civil remedies (Florida Civil Rights Act)"],
            ["627.70132", "s. 627.70132 Notice of property insurance claim"],
            ["733.702", "s. 733.702 Limitations on presentation of claims (probate)"],
            ["775.15", "s. 775.15 Time limitations (criminal)"],
          ].map(([section, label]) => (
            <li key={section}>
              <a href={flStatute(section)} rel="noopener">
                {label}
              </a>
            </li>
          ))}
          <li>
            <a href="https://laws.flrules.org/2023/15" rel="noopener">
              Chapter 2023-15, Laws of Florida (HB 837)
            </a>
          </li>
        </ul>
      ),
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Statutes of limitations"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="How long you have to sue, or the state has to charge a crime, in Florida: every deadline with its statute, start date and the notice deadlines that come first."
      description={DESCRIPTION}
      updated={UPDATED}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: UPDATED,
        sources: [flStatute("95.11"), flStatute("775.15"), "https://laws.flrules.org/2023/15"],
        variables: ["Claim type", "Deadline", "Start of the limitation period", "Statute"],
        spatial: "Florida",
      })}
    >
      <p>
        Related: <Link href="/data/florida-judicial-circuits/">Florida&apos;s judicial circuits</Link> ·{" "}
        <Link href="/rankings/">lawyer rankings</Link>
      </p>
    </TrustPage>
  );
}
