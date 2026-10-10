import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { DATA_INDEX_PATH, dataPage, dataPath, flStatute } from "@/lib/content/data-pages";
import { datasetJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

const PAGE = dataPage("florida-judicial-circuits");
const PATH = dataPath(PAGE.slug);
const UPDATED = "2026-10-09";
const DESCRIPTION =
  "All 20 Florida judicial circuits: the counties in each, the number of circuit judges, the district court of appeal that hears appeals, and the circuit for each major city.";

export const metadata: Metadata = buildMetadata({ title: PAGE.title, description: DESCRIPTION, path: PATH });

interface Circuit {
  n: number;
  name: string;
  counties: string[];
  judges: number;
  dca: number;
  cities: string;
}

/** s. 26.021 (counties), s. 26.031 (judges), ss. 35.02-35.044 (appellate districts). */
const CIRCUITS: Circuit[] = [
  { n: 1, name: "First", counties: ["Escambia", "Okaloosa", "Santa Rosa", "Walton"], judges: 26, dca: 1, cities: "Pensacola, Fort Walton Beach" },
  { n: 2, name: "Second", counties: ["Franklin", "Gadsden", "Jefferson", "Leon", "Liberty", "Wakulla"], judges: 17, dca: 1, cities: "Tallahassee" },
  { n: 3, name: "Third", counties: ["Columbia", "Dixie", "Hamilton", "Lafayette", "Madison", "Suwannee", "Taylor"], judges: 7, dca: 1, cities: "Lake City" },
  { n: 4, name: "Fourth", counties: ["Clay", "Duval", "Nassau"], judges: 37, dca: 5, cities: "Jacksonville" },
  { n: 5, name: "Fifth", counties: ["Citrus", "Hernando", "Lake", "Marion", "Sumter"], judges: 34, dca: 5, cities: "Ocala, The Villages" },
  { n: 6, name: "Sixth", counties: ["Pasco", "Pinellas"], judges: 45, dca: 2, cities: "St. Petersburg, Clearwater" },
  { n: 7, name: "Seventh", counties: ["Flagler", "Putnam", "St. Johns", "Volusia"], judges: 29, dca: 5, cities: "Daytona Beach, St. Augustine" },
  { n: 8, name: "Eighth", counties: ["Alachua", "Baker", "Bradford", "Gilchrist", "Levy", "Union"], judges: 14, dca: 1, cities: "Gainesville" },
  { n: 9, name: "Ninth", counties: ["Orange", "Osceola"], judges: 48, dca: 6, cities: "Orlando, Kissimmee, Winter Park" },
  { n: 10, name: "Tenth", counties: ["Hardee", "Highlands", "Polk"], judges: 30, dca: 6, cities: "Lakeland" },
  { n: 11, name: "Eleventh", counties: ["Miami-Dade"], judges: 83, dca: 3, cities: "Miami, Hialeah, Coral Gables" },
  { n: 12, name: "Twelfth", counties: ["DeSoto", "Manatee", "Sarasota"], judges: 24, dca: 2, cities: "Sarasota, Bradenton" },
  { n: 13, name: "Thirteenth", counties: ["Hillsborough"], judges: 45, dca: 2, cities: "Tampa" },
  { n: 14, name: "Fourteenth", counties: ["Bay", "Calhoun", "Gulf", "Holmes", "Jackson", "Washington"], judges: 14, dca: 1, cities: "Panama City" },
  { n: 15, name: "Fifteenth", counties: ["Palm Beach"], judges: 37, dca: 4, cities: "West Palm Beach, Boca Raton" },
  { n: 16, name: "Sixteenth", counties: ["Monroe"], judges: 4, dca: 3, cities: "Key West" },
  { n: 17, name: "Seventeenth", counties: ["Broward"], judges: 58, dca: 4, cities: "Fort Lauderdale, Hollywood" },
  { n: 18, name: "Eighteenth", counties: ["Brevard", "Seminole"], judges: 26, dca: 5, cities: "Melbourne, Sanford" },
  { n: 19, name: "Nineteenth", counties: ["Indian River", "Martin", "Okeechobee", "St. Lucie"], judges: 20, dca: 4, cities: "Port St. Lucie, Stuart" },
  { n: 20, name: "Twentieth", counties: ["Charlotte", "Collier", "Glades", "Hendry", "Lee"], judges: 32, dca: 6, cities: "Fort Myers, Naples, Cape Coral" },
];

/** ss. 35.02-35.044 and 35.05 (headquarters), 35.06 (judges). */
const DCAS: Array<{ n: number; name: string; seat: string; judges: string; section: string }> = [
  { n: 1, name: "First", seat: "Tallahassee", judges: "13", section: "35.02" },
  { n: 2, name: "Second", seat: "Pinellas County", judges: "15, falling to 13 as seats become vacant", section: "35.03" },
  { n: 3, name: "Third", seat: "Miami-Dade County", judges: "10", section: "35.04" },
  { n: 4, name: "Fourth", seat: "Palm Beach County", judges: "12", section: "35.042" },
  { n: 5, name: "Fifth", seat: "Daytona Beach", judges: "12", section: "35.043" },
  { n: 6, name: "Sixth", seat: "Lakeland", judges: "11", section: "35.044" },
];

const ORDINAL = ["", "1st", "2nd", "3rd", "4th", "5th", "6th"];

const COUNTIES = CIRCUITS.reduce((sum, c) => sum + c.counties.length, 0);
const JUDGES = CIRCUITS.reduce((sum, c) => sum + c.judges, 0);
const BUSIEST = [...CIRCUITS].sort((a, b) => b.judges - a.judges)[0];
const SMALLEST = [...CIRCUITS].sort((a, b) => a.judges - b.judges)[0];
const WIDEST = [...CIRCUITS].sort((a, b) => b.counties.length - a.counties.length)[0];

export default function JudicialCircuitsPage() {
  const sections: TrustSection[] = [
    {
      id: "short-version",
      heading: "How are Florida's courts organised, in short?",
      answer: `Florida's ${COUNTIES} counties are grouped into 20 judicial circuits with ${JUDGES} circuit judges in total; appeals go to 6 district courts of appeal and then the Florida Supreme Court.`,
      children: (
        <ul>
          <li>
            <strong>Circuit court</strong> hears felonies, family cases, probate and civil claims above $50,000 (
            <a href={flStatute("34.01")} rel="noopener">
              s. 34.01
            </a>
            ).
          </li>
          <li>
            <strong>County court</strong>, in every county, hears misdemeanors, traffic cases and civil claims up to $50,000.
          </li>
          <li>
            <strong>The {BUSIEST.name} Circuit ({BUSIEST.counties.join(", ")}) has the most judges ({BUSIEST.judges})</strong>; the{" "}
            {SMALLEST.name} ({SMALLEST.counties.join(", ")}) has the fewest ({SMALLEST.judges}).
          </li>
          <li>
            The {WIDEST.name} Circuit covers the most counties ({WIDEST.counties.length}).
          </li>
        </ul>
      ),
    },
    {
      id: "circuits",
      heading: "Which counties are in each Florida judicial circuit?",
      answer: "The table lists every circuit with its counties, its number of circuit judges and the district court of appeal that hears its appeals.",
      children: (
        <table>
          <thead>
            <tr>
              <th>Circuit</th>
              <th>Counties</th>
              <th>Main cities</th>
              <th>Circuit judges</th>
              <th>Appeals go to</th>
            </tr>
          </thead>
          <tbody>
            {CIRCUITS.map((c) => (
              <tr key={c.n} id={`circuit-${c.n}`}>
                <td>{c.name}</td>
                <td>{c.counties.join(", ")}</td>
                <td>{c.cities}</td>
                <td>{c.judges}</td>
                <td>{ORDINAL[c.dca]} DCA</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "appeals",
      heading: "Which district court of appeal covers my county?",
      answer: "Find your circuit above; the six district courts of appeal each cover between two and five circuits.",
      children: (
        <table>
          <thead>
            <tr>
              <th>District court of appeal</th>
              <th>Circuits</th>
              <th>Headquarters</th>
              <th>Judges</th>
            </tr>
          </thead>
          <tbody>
            {DCAS.map((d) => (
              <tr key={d.n}>
                <td>
                  <a href={flStatute(d.section)} rel="noopener">
                    {d.name} District
                  </a>
                </td>
                <td>
                  {CIRCUITS.filter((c) => c.dca === d.n)
                    .map((c) => c.name)
                    .join(", ")}
                </td>
                <td>{d.seat}</td>
                <td>{d.judges}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "changes",
      heading: "What changed recently?",
      answer: "A Sixth District Court of Appeal, seated in Lakeland, began work on January 1, 2023, and the Fourth Circuit (Jacksonville) moved to the Fifth District.",
      children: (
        <p>
          Chapter 2022-163, Laws of Florida, created the Sixth District for the Ninth, Tenth and Twentieth Circuits and redrew the others.{" "}
          <strong>Older guides that send Orlando, Lakeland or Fort Myers appeals to the Fifth or Second District, or Jacksonville appeals to the
          First, are out of date.</strong> The Second District is also shrinking: from July 1, 2025 each vacancy removes a seat until 13 remain (
          <a href={flStatute("35.06")} rel="noopener">
            s. 35.06(7)
          </a>
          ).
        </p>
      ),
    },
    {
      id: "why",
      heading: "Why does the circuit matter when you choose a lawyer?",
      answer: "Each circuit has its own local rules, judges and procedures, so a lawyer who practices regularly in your circuit knows how its courts work.",
      children: (
        <ul>
          <li>Your case is normally filed in the county where the defendant lives or where the events happened.</li>
          <li>Any Florida Bar member may practice in every circuit; local experience is an advantage, not a requirement.</li>
          <li>
            Our <Link href="/rankings/">rankings</Link> name the circuit for each city.
          </li>
        </ul>
      ),
    },
    {
      id: "faq",
      heading: "Frequently asked questions",
      answer: "Short answers to common questions about Florida's courts.",
      children: (
        <>
          <h3>Which circuit is Miami in?</h3>
          <p>
            <strong>The Eleventh Judicial Circuit, which covers only Miami-Dade County</strong> and has 83 circuit judges, the most in Florida.
          </p>
          <h3>Which circuit is Orlando in?</h3>
          <p>
            <strong>The Ninth Judicial Circuit (Orange and Osceola Counties)</strong>; its appeals go to the Sixth District Court of Appeal.
          </p>
          <h3>Which circuit is Tampa in?</h3>
          <p>
            <strong>The Thirteenth Judicial Circuit (Hillsborough County)</strong>; its appeals go to the Second District Court of Appeal.
          </p>
          <h3>Which circuit is Jacksonville in?</h3>
          <p>
            <strong>The Fourth Judicial Circuit (Clay, Duval and Nassau Counties)</strong>; since 2023 its appeals go to the Fifth District.
          </p>
          <h3>Where do appeals from a district court of appeal go?</h3>
          <p>
            <strong>To the Florida Supreme Court in Tallahassee</strong>, which takes only the cases the Florida Constitution allows, so most
            appeals end at the district court.
          </p>
        </>
      ),
    },
    {
      id: "sources",
      heading: "Sources",
      answer: "The Florida Statutes (2025 edition), read on October 9, 2026.",
      children: (
        <ul>
          <li>
            <a href={flStatute("26.021")} rel="noopener">
              s. 26.021 Judicial circuits; statewide grand jury
            </a>
          </li>
          <li>
            <a href={flStatute("26.031")} rel="noopener">
              s. 26.031 Judicial circuits; number of judges
            </a>
          </li>
          <li>
            <a href={flStatute("35.05")} rel="noopener">
              s. 35.05 Headquarters (district courts of appeal)
            </a>
          </li>
          <li>
            <a href={flStatute("35.06")} rel="noopener">
              s. 35.06 Organization of district courts of appeal
            </a>
          </li>
          <li>
            <a href={flStatute("34.01")} rel="noopener">
              s. 34.01 Jurisdiction of county court
            </a>
          </li>
        </ul>
      ),
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Judicial circuits"
      parent={{ name: "Data", path: DATA_INDEX_PATH }}
      eyebrow="Data"
      title={PAGE.title}
      lead="Every Florida county, its judicial circuit, the number of judges and the court that hears its appeals."
      description={DESCRIPTION}
      updated={UPDATED}
      sections={sections}
      jsonLd={datasetJsonLd({
        name: PAGE.title,
        path: PATH,
        description: DESCRIPTION,
        dateModified: UPDATED,
        sources: [flStatute("26.021"), flStatute("26.031"), flStatute("35.05")],
        variables: ["Judicial circuit", "Counties", "Number of circuit judges", "District court of appeal"],
        spatial: "Florida",
      })}
    >
      <p>
        Related: <Link href="/data/florida-statutes-of-limitations/">Florida statutes of limitations</Link>
      </p>
    </TrustPage>
  );
}
