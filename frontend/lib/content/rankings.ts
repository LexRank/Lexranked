import type { RankingSummary } from "@/types/api";

/**
 * Ranking URL resolution. Canonical ranking URLs are location/practice paths
 * (/rankings/florida/miami/personal-injury/), plus a context segment for
 * contextual rankings (/rankings/florida/miami/personal-injury/car-accidents/); a bare slug
 * (/rankings/<slug>/) redirects to the canonical path.
 */

export type RankingResolution =
  | { kind: "match"; ranking: RankingSummary }
  | { kind: "redirect"; to: string }
  | { kind: "none" };

export function rankingPathFromSegments(segments: string[]): string {
  return `/rankings/${segments.map((s) => s.toLowerCase()).join("/")}/`;
}

export function resolveRanking(segments: string[], rankings: RankingSummary[]): RankingResolution {
  if (segments.length === 0 || segments.length > 4) return { kind: "none" };
  const path = rankingPathFromSegments(segments);

  const byPath = rankings.filter((r) => r.path === path).sort((a, b) => a.id - b.id);
  if (byPath.length > 0) return { kind: "match", ranking: byPath[0]! };

  if (segments.length === 1) {
    const bySlug = rankings.find((r) => r.slug === segments[0]!.toLowerCase());
    if (bySlug?.path) return { kind: "redirect", to: bySlug.path };
  }
  return { kind: "none" };
}

/** Human label for a ranking's scope: "Personal Injury · Miami, FL". */
export function rankingScopeLabel(r: Pick<RankingSummary, "location" | "practiceArea">): string {
  const where = r.location?.city
    ? `${r.location.city}${r.location.stateCode ? `, ${r.location.stateCode}` : ""}`
    : (r.location?.state ?? "United States");
  return r.practiceArea ? `${r.practiceArea.name} · ${where}` : where;
}

/** Options for the homepage finder: only rankings that exist (non-thin). */
export interface FinderOption {
  path: string;
  locationLabel: string;
  locationKey: string;
  practiceLabel: string;
}

export function finderOptions(rankings: RankingSummary[]): FinderOption[] {
  return rankings
    .filter((r) => !r.isThin && r.path && !r.context)
    .map((r) => ({
      path: r.path as string,
      locationKey: `${r.location?.stateSlug ?? ""}/${r.location?.citySlug ?? ""}`,
      locationLabel: r.location?.city
        ? `${r.location.city}, ${r.location.stateCode ?? r.location.state ?? ""}`.replace(/, $/, "")
        : r.location?.state
          ? `All of ${r.location.state}`
          : "United States",
      practiceLabel: r.practiceArea?.name ?? "All practice areas",
    }))
    // Statewide rankings first (their key has no city: "florida/"), then cities A-Z.
    .sort(
      (a, b) =>
        Number(b.locationKey.endsWith("/")) - Number(a.locationKey.endsWith("/")) ||
        a.locationLabel.localeCompare(b.locationLabel) ||
        a.practiceLabel.localeCompare(b.practiceLabel),
    );
}
