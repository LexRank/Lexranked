import type { LawyerSummary, RankingContextDto, RankingEntry } from "@/types/api";

/**
 * Key attributes for a ranking card, chosen by the ranking's context (spec
 * §22–23). The card already shows rating, reviews and verification; this adds
 * what matters for the question the ranking answers:
 *
 * - ordinary ranking: experience, practice fit, bar status;
 * - case type: the case type (with its evidence status), practice area, experience;
 * - language: the language (with its evidence status), practice area, experience;
 * - client type: the client type (with its evidence status), practice area, experience.
 *
 * Only stored values are shown; a missing value is left out, never guessed.
 */

export interface KeyAttribute {
  key: string;
  label: string;
  /** "verified" | "sourced" for the context's own evidence. */
  evidence?: "verified" | "sourced";
  /** Where the evidence comes from (tooltip). */
  source?: string | null;
}

export const MAX_KEY_ATTRIBUTES = 3;

function practice(entry: RankingEntry): KeyAttribute | null {
  const names = entry.entity.practiceAreas.map((p) => p.name);
  return names.length > 0 ? { key: "practice", label: names.slice(0, 2).join(" · ") } : null;
}

function experience(entry: RankingEntry): KeyAttribute | null {
  const years = entry.keyFacts?.yearsExperience ?? null;
  if (years === null || entry.entity.type !== "lawyer") return null;
  return { key: "experience", label: `${years} ${years === 1 ? "year" : "years"} experience` };
}

function barStatus(entry: RankingEntry): KeyAttribute | null {
  if (entry.entity.type !== "lawyer") return null;
  const checks = (entry.entity as LawyerSummary).verification.checks;
  return checks.bar_status === "verified" && entry.keyFacts?.barStatus === "active" ? { key: "bar", label: "Bar status verified", evidence: "verified" } : null;
}

function qualification(entry: RankingEntry, context: RankingContextDto): KeyAttribute | null {
  const q = entry.qualification;
  if (!q) return null;
  const label = context.type === "language" ? `Speaks ${q.value}` : context.type === "client_type" ? `Serves ${context.value}` : context.label;
  return {
    key: "context",
    label,
    evidence: q.status === "verified" ? "verified" : "sourced",
    source: q.source?.name ?? null,
  };
}

export function contextualAttributes(entry: RankingEntry, context: RankingContextDto | null | undefined): KeyAttribute[] {
  const picks = context
    ? [qualification(entry, context), practice(entry), experience(entry)]
    : [experience(entry), practice(entry), barStatus(entry)];
  return picks.filter((a): a is KeyAttribute => a !== null).slice(0, MAX_KEY_ATTRIBUTES);
}
