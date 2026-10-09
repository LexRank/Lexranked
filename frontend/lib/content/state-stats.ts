import "server-only";

import { cache } from "react";
import { getStateStats } from "@/lib/wordpress/api";

/** Florida's figures, fetched once per request for metadata and page (null before API 1.23). */
export const floridaStats = cache(() => getStateStats("florida"));
