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
    description: "Recognition and standing documented by verifiable, independent sources.",
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
    description: "Years in practice, based on bar admission records where available.",
  },
  {
    key: "practice_relevance",
    label: "Practice-area relevance",
    weight: 15,
    description: "How focused the lawyer or firm is on the practice area being ranked.",
  },
  {
    key: "credentials",
    label: "Professional credentials",
    weight: 10,
    description: "Bar standing, licensing and other verifiable professional credentials.",
  },
  {
    key: "local_relevance",
    label: "Local relevance",
    weight: 5,
    description: "Presence and activity in the city or state being ranked.",
  },
  {
    key: "data_quality",
    label: "Data quality",
    weight: 5,
    description: "Completeness, freshness and verification of the underlying data.",
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
