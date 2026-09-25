import type { MetadataRoute } from "next";
import { readServerEnv } from "@/lib/config/server-env";

// The sitemap reference is added in Phase 3 together with app/sitemap.ts.
export default function robots(): MetadataRoute.Robots {
  if (!readServerEnv().allowIndexing) {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: { userAgent: "*", allow: "/", disallow: ["/api/", "/search/", "/status/"] },
  };
}
