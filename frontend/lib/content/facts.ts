import type { FactDto } from "@/types/api";

/**
 * Presentation of normalised facts for the "Sources & Verification" panel
 * (spec §16-18): grouped by category, each with its own source and date.
 * Pure: the values come from the fact layer as stored.
 */

export const FACT_GROUPS: Array<{ key: string; label: string }> = [
  { key: "credentials", label: "Credentials" },
  { key: "experience", label: "Experience" },
  { key: "practice", label: "Practice" },
  { key: "reviews", label: "Reviews" },
  { key: "location", label: "Location" },
  { key: "contact", label: "Contact" },
  { key: "language", label: "Languages" },
  { key: "organization", label: "Firm" },
  { key: "identity", label: "Identity" },
];

/** Redundant with the page heading. */
const HIDDEN = new Set(["first_name", "last_name", "firm_id"]);

const humanizeSlug = (s: string) => s.replace(/-/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

export function factValue(fact: Pick<FactDto, "attribute" | "value" | "unit">, practiceNames: Record<string, string> = {}): string {
  const v = fact.value;
  if (v === null || v === undefined || v === "") return "-";
  if (Array.isArray(v)) {
    return v
      .map((item) => {
        if (item && typeof item === "object") {
          const o = item as Record<string, unknown>;
          return [o.degree, o.institution ?? o.name, o.issuer, o.year].filter((x) => typeof x === "string" && x !== "").join(", ");
        }
        const s = String(item);
        return fact.attribute === "practice_areas" || fact.attribute === "case_types" || fact.attribute === "client_types" ? (practiceNames[s] ?? humanizeSlug(s)) : s;
      })
      .filter(Boolean)
      .join(v.some((item) => item && typeof item === "object") ? "; " : ", ");
  }
  if (fact.attribute === "rating") return `${Number(v).toFixed(1)} / 5`;
  if (fact.attribute === "review_count") return `${Number(v).toLocaleString("en-US")} reviews`;
  if (fact.attribute === "years_experience") return `${v} years`;
  if (fact.attribute === "bar_status") return String(v).charAt(0).toUpperCase() + String(v).slice(1);
  return String(v);
}

export interface FactGroup {
  key: string;
  label: string;
  facts: FactDto[];
}

export function groupFacts(facts: FactDto[]): FactGroup[] {
  const shown = facts.filter((f) => !HIDDEN.has(f.attribute));
  const known = new Set(FACT_GROUPS.map((g) => g.key));
  const groups = FACT_GROUPS.map((g) => ({ ...g, facts: shown.filter((f) => f.category === g.key) }));
  const other = shown.filter((f) => !known.has(f.category));
  if (other.length > 0) groups.push({ key: "other", label: "Other", facts: other });
  return groups.filter((g) => g.facts.length > 0);
}

/** The most recent verification date among the facts (for "Data last verified"). */
export function lastVerified(facts: FactDto[]): string | null {
  const dates = facts.map((f) => f.verifiedAt).filter((d): d is string => !!d).sort();
  return dates.length > 0 ? dates[dates.length - 1]! : null;
}
