/**
 * Public description of the LexRank methodology (docs/ranking-methodology.md).
 *
 * Weights are displayed for transparency only; the frontend never computes
 * scores. When the Phase 4 engine exposes score versions through the API,
 * this module will read them from there instead.
 */

export const METHODOLOGY_VERSION = "LexRank v1.2";

/** "LexRank v1.2" for a stored score version; the default when unknown. */
export function methodologyLabel(version?: string | null): string {
  return version ? `LexRank ${version}` : METHODOLOGY_VERSION;
}

export interface MethodologyComponent {
  key: string;
  label: string;
  weight: number;
  description: string;
}

export const METHODOLOGY_COMPONENTS: MethodologyComponent[] = [
  {
    key: "reputation",
    label: "Reputation",
    weight: 20,
    description: "Recorded awards and board certifications, counted up to five.",
  },
  {
    key: "review_strength",
    label: "Review strength",
    weight: 0,
    description:
      "Client ratings adjusted for review volume with a Bayesian average. Not scored in v1.2: client reviews are collected and shown on profiles, and will count once enough exist.",
  },
  {
    key: "experience",
    label: "Experience",
    weight: 30,
    description: "Years in practice, with full credit at 25 years.",
  },
  {
    key: "practice_relevance",
    label: "Practice-area relevance",
    weight: 20,
    description: "Whether the lawyer or firm practices in the ranked area, and how focused that practice is.",
  },
  {
    key: "credentials",
    label: "Professional credentials",
    weight: 15,
    description: "Active bar status, a verified license and education on record (firms: verified business and website).",
  },
  {
    key: "local_relevance",
    label: "Local relevance",
    weight: 5,
    description: "Whether the lawyer or firm is based in the ranked city or state.",
  },
  {
    key: "data_quality",
    label: "Data quality",
    weight: 10,
    description: "How complete, source-backed and verified the profile's key facts are.",
  },
];

export const METHODOLOGY_PRINCIPLES = [
  {
    title: "Payment never buys rank",
    body: "Organic scores and commercial data are stored separately, and the ranking code cannot read commercial data. Claimed profiles, premium profiles and featured or sponsored placements are always labelled, shown outside the ranked list and never change a score or position.",
  },
  {
    title: "Every fact has a source",
    body: "Important facts are traceable to official registries, firm websites or other documented sources. Unknown stays unknown — we never guess.",
  },
  {
    title: "Reproducible, not subjective",
    body: "Scores are calculated deterministically from stored data. The same data and methodology version always produce the same result.",
  },
  {
    title: "Verified and kept fresh",
    body: "A profile is marked verified only when every required check has passed, and each data point shows when it was last verified.",
  },
] as const;

/**
 * Merge weights published by the API (the engine's source of truth) into the
 * public component descriptions. Falls back to the documented defaults.
 */
export function componentsWithWeights(weights: Array<{ key: string; weight: number }> | null): MethodologyComponent[] {
  // A component weighted 0 is not part of the active version (e.g. review strength in v1.2).
  if (!weights || weights.length === 0) return METHODOLOGY_COMPONENTS.filter((c) => c.weight > 0);
  const byKey = new Map(weights.map((w) => [w.key, w.weight]));
  return METHODOLOGY_COMPONENTS.map((c) => ({ ...c, weight: byKey.get(c.key) ?? c.weight })).filter((c) => c.weight > 0);
}
