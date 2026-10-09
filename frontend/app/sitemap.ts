import type { MetadataRoute } from "next";
import { buildSitemap, STATIC_PATHS } from "@/lib/content/sitemap";
import { allArticles, allLawFirms, allLawyers, allRankings } from "@/lib/data/loaders";
import { absoluteUrl } from "@/lib/seo/urls";
import { getCities, getPracticeAreas, getStateStats, getStates } from "@/lib/wordpress/api";

export const revalidate = 3600;

/** Dynamic sitemap: indexable pages only (see lib/content/sitemap.ts). */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  try {
    const [lawyers, lawFirms, rankings, states, cities, practiceAreas, articles, stats] = await Promise.all([
      allLawyers(),
      allLawFirms(),
      allRankings(),
      getStates().then((r) => r.data),
      getCities().then((r) => r.data),
      getPracticeAreas().then((r) => r.data),
      // Older CMS versions without /articles: publish the rest of the sitemap.
      allArticles().catch(() => []),
      getStateStats("florida").catch(() => null),
    ]);
    return buildSitemap({ lawyers, lawFirms, rankings, states, cities, practiceAreas, articles, stats });
  } catch {
    // API unavailable (e.g. during a build without WordPress): publish the static pages only.
    return STATIC_PATHS.map((path) => ({ url: absoluteUrl(path) }));
  }
}
