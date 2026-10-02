import type { RankingSourceDto } from "@/types/api";

/**
 * Sources behind a ranking, grouped by site and source type: twenty bar
 * profile pages on floridabar.org are one source ("The Florida Bar, 20
 * profile pages"), not twenty identical rows. Each profile still lists the
 * exact page behind each of its facts.
 */
export interface SourceGroup {
  key: string;
  name: string;
  /** The page itself when the group has one; otherwise the site's home page. */
  url: string | null;
  tier: number | null;
  tierLabel: string | null;
  pages: number;
  facts: number;
  /** Entities with at least one fact from the group (capped at the ranked count). */
  entities: number;
}

function origin(url: string | null): string | null {
  if (!url || !/^https?:\/\//.test(url)) return null;
  try {
    return `${new URL(url).origin}/`;
  } catch {
    return null;
  }
}

export function groupRankingSources(sources: RankingSourceDto[], rankedCount: number): SourceGroup[] {
  const groups = new Map<string, SourceGroup>();
  for (const s of sources) {
    const name = s.publisher || s.name;
    const key = `${name.toLowerCase()}|${s.type ?? ""}`;
    const g = groups.get(key);
    if (g) {
      g.pages += 1;
      g.facts += s.facts;
      g.entities += s.entities;
      g.url = origin(s.url) ?? g.url;
      if (s.tier !== null && (g.tier === null || s.tier < g.tier)) {
        g.tier = s.tier;
        g.tierLabel = s.tierLabel;
      }
    } else {
      groups.set(key, { key, name, url: s.url && /^https?:\/\//.test(s.url) ? s.url : null, tier: s.tier, tierLabel: s.tierLabel, pages: 1, facts: s.facts, entities: s.entities });
    }
  }
  return [...groups.values()]
    .map((g) => ({ ...g, entities: rankedCount > 0 ? Math.min(g.entities, rankedCount) : g.entities }))
    .sort((a, b) => (a.tier ?? 99) - (b.tier ?? 99) || b.facts - a.facts || a.name.localeCompare(b.name));
}
