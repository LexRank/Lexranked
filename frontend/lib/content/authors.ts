/**
 * Author profiles. A guide's byline links to a profile when the WordPress
 * author's slug (user_nicename) matches an entry here. Every statement in a
 * profile must be true and confirmed by the person; nothing is invented.
 */

export interface AuthorPhoto {
  src: string;
  alt: string;
  width: number;
  height: number;
}

export interface AuthorProfile {
  slug: string;
  name: string;
  jobTitle: string;
  /** One or two sentences: who the author is and what they cover. */
  summary: string;
  portrait: AuthorPhoto;
  photos: AuthorPhoto[];
  facts: Array<[string, string]>;
  focus: string[];
  background: string;
  interests: string;
}

export const AUTHORS: AuthorProfile[] = [
  {
    slug: "ryan-mitchell",
    name: "Ryan Mitchell",
    jobTitle: "Editor",
    summary:
      "Ryan Mitchell is a LexRanked editor with 21 years in the financial industry. He covers the money side of legal decisions: lawyer fees, settlements, insurance and what clients actually take home.",
    portrait: { src: "/authors/ryan-mitchell/portrait.webp", alt: "Portrait of Ryan Mitchell", width: 600, height: 800 },
    photos: [
      { src: "/authors/ryan-mitchell/surfing.webp", alt: "Ryan Mitchell surfing at sunset", width: 600, height: 800 },
      { src: "/authors/ryan-mitchell/golf.webp", alt: "Ryan Mitchell at a golf driving range", width: 600, height: 800 },
      { src: "/authors/ryan-mitchell/casual.webp", alt: "Ryan Mitchell on a phone call outdoors", width: 600, height: 800 },
    ],
    facts: [
      ["Role at LexRanked", "Editor"],
      ["Experience", "21 years in the financial industry"],
      ["Age", "43"],
      ["Focus", "Lawyer fees, settlements, insurance, choosing a lawyer"],
      ["Outside work", "Surfing and golf"],
    ],
    focus: [
      "lawyer fees and fee agreements, including contingency fees and case costs;",
      "settlements: what comes out of a recovery and what the client keeps;",
      "insurance questions, such as PIP and uninsured motorist coverage;",
      "how to compare and check a lawyer before hiring one.",
    ],
    background:
      "Ryan has spent 21 years working in the financial industry. That experience shapes how he reads fee agreements, closing statements and settlement figures: he works through the numbers the way a client will see them, and turns the rules into worked examples and checklists.",
    interests: "Outside work, Ryan surfs and plays golf.",
  },
];

export function authorBySlug(slug: string | null | undefined): AuthorProfile | null {
  if (!slug) return null;
  return AUTHORS.find((a) => a.slug === slug) ?? null;
}

export function authorPath(slug: string): string {
  return `/authors/${slug}/`;
}
