import Link from "next/link";
import type { TrustSection } from "@/components/TrustPage";
import { dateLabel } from "@/lib/content/data-pages";
import type { StateStatsDto } from "@/types/api";

/** The closing "where these figures come from" section shared by the statistics pages. */
export function figuresSection(stats: StateStatsDto, sample: string): TrustSection {
  return {
    id: "about-figures",
    heading: "Where do these figures come from?",
    answer: `From the ${stats.lawyers} Florida lawyer profiles LexRanked publishes, recalculated automatically; these figures were calculated on ${dateLabel(stats.calculatedAt)}.`,
    children: (
      <ul>
        <li>
          Every profile is built from the lawyer&apos;s official record in{" "}
          <a href="https://www.floridabar.org/directories/find-mbr/" rel="noopener">
            The Florida Bar&apos;s member directory
          </a>
          , checked as described in <Link href="/verified/">how we verify</Link>.
        </li>
        <li>
          <strong>The figures describe the lawyers LexRanked tracks, not every lawyer in Florida.</strong> Our research starts from The
          Florida Bar&apos;s board certification lists in the cities we cover, so{" "}
          {stats.certified === stats.lawyers ? "all of them are" : `${stats.certified} of the ${stats.lawyers} are`} board certified.
        </li>
        <li>{sample}</li>
        <li>No figure identifies a single lawyer. To check one lawyer, open their profile from a ranking.</li>
      </ul>
    ),
  };
}
