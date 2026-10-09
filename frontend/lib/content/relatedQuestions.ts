import type { RankingDetail, RankingSummary } from "@/types/api";
import { formatScore } from "@/lib/format";
import { compareHref } from "./compare";

/**
 * "Related questions" for ranking pages (spec §24). Every question is asked
 * only when the ranking's own data can answer it, and every answer is built
 * from that data — no generic SEO FAQ, no generated prose.
 */

export interface RelatedQuestion {
  question: string;
  answer: string;
  link?: { href: string; label: string };
}

function names(items: string[]): string {
  if (items.length <= 1) return items.join("");
  return `${items.slice(0, -1).join(", ")} and ${items[items.length - 1]}`;
}

export function relatedQuestions(ranking: RankingDetail, all: RankingSummary[] = []): RelatedQuestion[] {
  const entries = ranking.entries;
  if (entries.length === 0) return [];
  const noun = ranking.entityType === "law_firm" ? "firms" : "lawyers";
  const version = entries[0]?.scoreVersion ? `LexRank ${entries[0].scoreVersion}` : "LexRank";
  const out: RelatedQuestion[] = [];

  // The one methodology question; the methodology page has the detail.
  out.push({
    question: `How were these ${noun} ranked?`,
    answer: `By their ${version} score, calculated only from facts backed by a cited source. Payment never changes a position.`,
    link: { href: "/methodology/", label: "How we rank" },
  });

  const verified = entries.filter((e) => e.entity.verification.status === "verified").map((e) => e.entity.name);
  out.push({
    question: `Which ${noun} are verified?`,
    answer:
      verified.length === 0
        ? `None of the ${entries.length} ranked ${noun} has a fully verified profile yet.`
        : `${verified.length} of the ${entries.length} ranked ${noun} ${verified.length === 1 ? "has" : "have"} fully verified profiles: ${names(verified.slice(0, 5))}${verified.length > 5 ? " and others" : ""}.`,
  });

  const reviewed = entries.filter((e) => typeof e.entity.reviewCount === "number" && e.entity.reviewCount > 0).sort((a, b) => (b.entity.reviewCount ?? 0) - (a.entity.reviewCount ?? 0));
  if (reviewed.length >= 2) {
    out.push({
      question: `Which ${noun} have the most reviews?`,
      answer: `${names(reviewed.slice(0, 3).map((e) => `${e.entity.name} (${(e.entity.reviewCount ?? 0).toLocaleString("en-US")})`))}. Review counts come from the cited review platforms; ratings are adjusted for volume in the score.`,
    });
  }

  // Narrower "best for" rankings that passed their data threshold.
  for (const child of all.filter((r) => r.context?.parent?.id === ranking.id && !r.isThin && r.path)) {
    const c = child.context!;
    out.push({
      question:
        c.type === "language"
          ? `Which ${noun} speak ${c.value.charAt(0).toUpperCase()}${c.value.slice(1)}?`
          : c.type === "client_type"
            ? `Which ${noun} serve ${c.value}?`
            : `Which ${noun} list ${c.label.toLowerCase()} among their case types?`,
      answer: `${c.eligibility.qualified} of the ${c.eligibility.parentCount} ${noun} in this ranking have it on record, ${c.eligibility.verified} confirmed by a verified fact or an official record. They are ranked separately by the same LexRank score.`,
      link: { href: child.path as string, label: child.title },
    });
  }

  const second = entries[1];
  const behind = second?.why?.behind;
  // Only when there is a difference to explain (a tie has none).
  if (second && behind && behind.scoreGap > 0) {
    const first = entries[0]!;
    const gaps = behind.components.filter((c) => c.delta > 0).slice(0, 2).map((c) => c.label.toLowerCase());
    const href = compareHref(ranking.entityType === "law_firm" ? "law_firm" : "lawyer", [first.entity.entityId, second.entity.entityId]);
    out.push({
      question: `What is the difference between the top two ${noun}?`,
      answer: `${first.entity.name} scores ${formatScore(first.score)} and ${second.entity.name} ${formatScore(second.score)}, a gap of ${behind.scoreGap.toFixed(2)} points${gaps.length > 0 ? `, mostly in ${names(gaps)}` : ""}.`,
      ...(href ? { link: { href, label: "Compare them side by side" } } : {}),
    });
  }
  return out;
}
