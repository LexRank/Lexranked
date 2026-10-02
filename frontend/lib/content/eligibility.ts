/**
 * Rules for which pages exist and which are indexable (spec §14–15, §18, §20, §32).
 *
 * Since Etap G the CMS decides with its page eligibility engine and sends the
 * decision with each DTO (`eligibility`); these functions use it when present
 * and fall back to the simpler local rules for older API versions.
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

type Decided = { eligibility?: { exists: boolean; indexable: boolean } | null };

const fromApi = (item: Decided): Eligibility | null =>
  item.eligibility ? { exists: item.eligibility.exists, indexable: item.eligibility.exists && item.eligibility.indexable } : null;

/** State, city and practice-area hub pages. */
export function hubEligibility(counts: HubCounts & Decided, entities: ReadonlyArray<{ isDemo: boolean }>): Eligibility {
  const decided = fromApi(counts);
  if (decided) return decided;
  const exists = counts.lawyerCount >= MIN_LAWYERS_FOR_HUB_PAGE;
  const realEntities = entities.filter((e) => !e.isDemo).length;
  return { exists, indexable: exists && realEntities >= MIN_LAWYERS_FOR_HUB_PAGE };
}

/** Ranking pages: thin rankings do not exist; demo rankings are never indexed. */
export function rankingEligibility(ranking: { isThin: boolean; indexable: boolean } & Decided): Eligibility {
  const decided = fromApi(ranking);
  if (decided) return decided;
  return { exists: !ranking.isThin, indexable: !ranking.isThin && ranking.indexable };
}

/** Lawyer / firm profiles exist when published; demo profiles are never indexed. */
export function profileEligibility(entity: { isDemo: boolean } & Decided): Eligibility {
  const decided = fromApi(entity);
  if (decided) return decided;
  return { exists: true, indexable: !entity.isDemo };
}

/** Listing pages always exist; they are indexable only with real content. */
export function listingEligibility(items: ReadonlyArray<{ isDemo: boolean }>): Eligibility {
  return { exists: true, indexable: items.some((i) => !i.isDemo) };
}

/** Minimum words for an indexable article (shorter guides are "thin"). */
export const MIN_ARTICLE_WORDS = 300;

/** Articles exist when published; thin or demo articles are never indexed. */
export function articleEligibility(article: { isDemo: boolean; wordCount: number } & Decided): Eligibility {
  const decided = fromApi(article);
  if (decided) return decided;
  return { exists: true, indexable: !article.isDemo && article.wordCount >= MIN_ARTICLE_WORDS };
}

/** Index pages (/states/, /cities/, /practice-areas/, /rankings/) are thin until they link to this many pages. */
export const MIN_INDEX_PAGE_ENTRIES = 3;

export function indexPageIndexable(existingPages: number): boolean {
  return existingPages >= MIN_INDEX_PAGE_ENTRIES;
}
