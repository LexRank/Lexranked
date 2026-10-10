import Link from "next/link";
import type { MarketDto } from "@/types/api";
import { formatCount, formatDate, isoDate } from "@/lib/format";

/**
 * Market statistics (spec §33-35): computed by the CMS from stored data. The
 * page only formats them; figures below the minimum sample arrive as null and
 * are shown as withheld, never estimated.
 */
export function MarketStats({ market, title, link }: { market: MarketDto; title: string; link?: { href: string; label: string } | null }) {
  const s = market.stats;
  if (s.lawyers + s.firms === 0) return null;
  const tiles: Array<{ label: string; value: string; note?: string }> = [
    { label: "Lawyers", value: formatCount(s.lawyers) ?? "0" },
    { label: "Law firms", value: formatCount(s.firms) ?? "0" },
    { label: "Verified professional data", value: `${s.verifiedLawyers} of ${s.lawyers}` },
    s.averageRating
      ? { label: "Average rating", value: `${s.averageRating.value.toFixed(1)} ★`, note: `${s.averageRating.sample} lawyers` }
      : { label: "Average rating", value: "Withheld", note: "too few sourced ratings" },
    s.medianReviewCount
      ? { label: "Median review count", value: formatCount(s.medianReviewCount.value) ?? "0", note: `${s.medianReviewCount.sample} lawyers` }
      : { label: "Median review count", value: "Withheld", note: "too few sourced counts" },
    ...(s.medianExperience ? [{ label: "Median experience", value: `${s.medianExperience.value} years`, note: `${s.medianExperience.sample} lawyers` }] : []),
    ...(s.mostCommonPractice && !market.scope.practiceArea
      ? [{ label: "Most common practice", value: s.mostCommonPractice.name, note: `${s.mostCommonPractice.count} lawyers` }]
      : []),
  ];
  const verified = formatDate(s.dataVerifiedAt);
  const calculated = formatDate(s.calculatedAt);
  return (
    <section id="market" className="card" aria-labelledby="market-heading">
      <h2 id="market-heading" style={{ fontSize: "1.4rem" }}>
        {title}
      </h2>
      {market.summary && <p style={{ marginTop: 0 }}>{market.summary}</p>}
      <dl className="overview__facts market__facts">
        {tiles.map((t) => (
          <div key={t.label}>
            <dt>{t.label}</dt>
            <dd>
              {t.value}
              {t.note && <span className="market__note">{t.note}</span>}
            </dd>
          </div>
        ))}
      </dl>
      <p className="muted" style={{ fontSize: "0.85rem", marginBottom: 0 }}>
        Calculated by LexRanked from its own records
        {calculated && (
          <>
            {" "}
            on <time dateTime={isoDate(s.calculatedAt)}>{calculated}</time>
          </>
        )}
        {verified && (
          <>
            ; data verified <time dateTime={isoDate(s.dataVerifiedAt)}>{verified}</time>
          </>
        )}
        . Ratings and review counts count only when backed by a source; figures from fewer than three profiles are withheld.
        {s.demoProfiles > 0 && " Includes demo profiles."}
        {link && (
          <>
            {" "}
            <Link href={link.href}>{link.label}</Link>
          </>
        )}
      </p>
    </section>
  );
}
