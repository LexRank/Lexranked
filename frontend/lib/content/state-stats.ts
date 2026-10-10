import "server-only";

import { cache } from "react";
import { load } from "@/lib/data/loaders";
import { getStateStats } from "@/lib/wordpress/api";

/**
 * Florida's figures, fetched once per request for metadata and page. Null
 * before API 1.23 or when the CMS is unreachable (e.g. a build without
 * WordPress): the statistics pages then 404 until the next revalidation.
 */
export const floridaStats = cache(async () => {
  const result = await load(() => getStateStats("florida"));
  return result.ok ? result.data : null;
});
