import type { RankingDetail } from "@/types/api";
import { formatDate, formatScore, inSentence } from "@/lib/format";
import { methodologyLabel } from "@/lib/methodology";

/**
 * Facts and the "answer-first" summary for ranking pages (SEO + GEO).
 *
 * Everything here is derived deterministically from the ranking data, so it
 * can never state something the data does not support. AI answer engines and
 * search snippets can quote the summary verbatim.
 */

export interface RankingFacts {
  count: number;
  verifiedCount: number;
  ratedCount: number;
  /** Mean of known star ratings (unweighted), rounded to 1 decimal. */
  averageRating: number | null;
  totalReviews: number;
  topNames: Array<{ name: string; score: number }>;
}

export function rankingFacts(ranking: Pick<RankingDetail, "entries">): RankingFacts {
  const entries = ranking.entries;
  const ratings = entries.map((e) => e.entity.rating).filter((r): r is number => typeof r === "number");
  const reviews = entries.map((e) => e.entity.reviewCount).filter((r): r is number => typeof r === "number");
  return {
    count: entries.length,
    verifiedCount: entries.filter((e) => e.entity.verification.status === "verified").length,
    ratedCount: ratings.length,
    averageRating: ratings.length > 0 ? Math.round((ratings.reduce((a, b) => a + b, 0) / ratings.length) * 10) / 10 : null,
    totalReviews: reviews.reduce((a, b) => a + b, 0),
    topNames: entries.slice(0, 3).map((e) => ({ name: e.entity.name, score: e.score })),
  };
}

function joinNames(items: string[]): string {
  if (items.length <= 1) return items.join("");
  return `${items.slice(0, -1).join(", ")} and ${items[items.length - 1]}`;
}

/** Scope phrase: "personal injury lawyers in Miami, Florida". */
export function rankingScopePhrase(ranking: Pick<RankingDetail, "entityType" | "practiceArea" | "location" | "context">): string {
  const noun = ranking.entityType === "law_firm" ? "law firms" : "lawyers";
  const practice = ranking.practiceArea ? `${inSentence(ranking.practiceArea.name)} ` : "";
  const where = ranking.location?.city
    ? `${ranking.location.city}${ranking.location.state ? `, ${ranking.location.state}` : ""}`
    : ranking.location?.state;
  const base = `${practice}${noun}${where ? ` in ${where}` : ""}`;
  const c = ranking.context;
  if (!c) return base;
  // Contextual rankings (Etap F): the scope names the qualifier the data confirms.
  if (c.type === "language") return `${c.label} ${base}`;
  if (c.type === "client_type") return `${base} serving ${c.value}`;
  return `${base} who list ${c.label.toLowerCase()} among their case types`;
}

/**
 * Two to three factual sentences, e.g.:
 * "As of September 26, 2026, the top-ranked personal injury lawyers in Miami,
 * Florida are A (LexRank 94.21), B (90.40) and C (88.75). LexRanked ranked 8
 * lawyers with the LexRank v1.0 methodology; 5 have fully verified profiles."
 */
export function rankingAnswer(ranking: RankingDetail, facts: RankingFacts = rankingFacts(ranking), options: { brief?: boolean } = {}): string {
  if (facts.count === 0) return "";
  const scope = rankingScopePhrase(ranking);
  const asOf = formatDate(ranking.updatedAt);
  const top = facts.topNames.map((t, i) => `${t.name} (${i === 0 ? "LexRank " : ""}${formatScore(t.score)})`);
  const lead = `${asOf ? `As of ${asOf}, the` : "The"} top-ranked ${scope} ${top.length === 1 ? "is" : "are"} ${joinNames(top)}.`;
  const noun = ranking.entityType === "law_firm" ? (facts.count === 1 ? "law firm" : "law firms") : facts.count === 1 ? "lawyer" : "lawyers";
  const verified = facts.verifiedCount > 0 ? `; ${facts.verifiedCount} of them ${facts.verifiedCount === 1 ? "has a" : "have"} fully verified ${facts.verifiedCount === 1 ? "profile" : "profiles"}` : "";
  const method = `LexRanked ranked ${facts.count} ${noun} with the ${methodologyLabel(ranking.entries[0]?.scoreVersion)} methodology${verified}.`;
  const ratings =
    facts.averageRating !== null && facts.totalReviews > 0
      ? ` Their profiles show an average client rating of ${facts.averageRating.toFixed(1)} out of 5 across ${facts.totalReviews.toLocaleString("en-US")} reviews, for information only.`
      : "";
  // Beside the ranking's own summary, only the lead is new: counts and the methodology are already shown on the page.
  return options.brief ? lead : `${lead} ${method}${ratings}`;
}
