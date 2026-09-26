/**
 * Rules for which pages exist and which are indexable (spec §18, §20, §32).
 *
 * A page exists only when it has useful, differentiated data. A page is
 * indexable only when it exists AND is built from real (non-demo) data.
 * Keeping these rules in one place keeps pages, robots meta and the sitemap
 * consistent.
 */

/** Minimum published lawyers for a state / city / practice-area page. */
export const MIN_LAWYERS_FOR_HUB_PAGE = 3;

export interface HubCounts {
  lawyerCount: number;
  lawFirmCount: number;
}

export interface Eligibility {
  exists: boolean;
  indexable: boolean;
}

/** State, city and practice-area hub pages. */
export function hubEligibility(counts: HubCounts, entities: ReadonlyArray<{ isDemo: boolean }>): Eligibility {
  const exists = counts.lawyerCount >= MIN_LAWYERS_FOR_HUB_PAGE;
  const realEntities = entities.filter((e) => !e.isDemo).length;
  return { exists, indexable: exists && realEntities >= MIN_LAWYERS_FOR_HUB_PAGE };
}

/** Ranking pages: thin rankings do not exist; demo rankings are never indexed. */
export function rankingEligibility(ranking: { isThin: boolean; indexable: boolean }): Eligibility {
  return { exists: !ranking.isThin, indexable: !ranking.isThin && ranking.indexable };
}

/** Lawyer / firm profiles exist when published; demo profiles are never indexed. */
export function profileEligibility(entity: { isDemo: boolean }): Eligibility {
  return { exists: true, indexable: !entity.isDemo };
}

/** Listing pages always exist; they are indexable only with real content. */
export function listingEligibility(items: ReadonlyArray<{ isDemo: boolean }>): Eligibility {
  return { exists: true, indexable: items.some((i) => !i.isDemo) };
}
