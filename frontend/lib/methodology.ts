/**
 * Public description of the LexRank methodology (docs/ranking-methodology.md).
 *
 * Weights are displayed for transparency only; the frontend never computes
 * scores. When the Phase 4 engine exposes score versions through the API,
 * this module will read them from there instead.
 */

export const METHODOLOGY_VERSION = "LexRank v1.0";

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
    weight: 30,
    description: "Recorded awards and the volume of public client reviews.",
  },
  {
    key: "review_strength",
    label: "Review strength",
    weight: 20,
    description:
      "Client ratings adjusted for review volume with a Bayesian average, so a handful of perfect reviews cannot outrank hundreds of strong ones.",
  },
  {
    key: "experience",
    label: "Experience",
    weight: 15,
    description: "Years in practice, with full credit at 25 years.",
  },
  {
    key: "practice_relevance",
    label: "Practice-area relevance",
    weight: 15,
    description: "Whether the lawyer or firm practices in the ranked area, and how focused that practice is.",
  },
  {
    key: "credentials",
    label: "Professional credentials",
    weight: 10,
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
    weight: 5,
    description: "How complete, source-backed and verified the profile's key facts are.",
  },
];

export const METHODOLOGY_PRINCIPLES = [
  {
    title: "Payment never buys rank",
    body: "Organic scores and commercial status are stored separately. Featured or sponsored placements are always labelled and never change a score.",
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
  if (!weights || weights.length === 0) return METHODOLOGY_COMPONENTS;
  const byKey = new Map(weights.map((w) => [w.key, w.weight]));
  return METHODOLOGY_COMPONENTS.map((c) => ({ ...c, weight: byKey.get(c.key) ?? c.weight }));
}
