import type { MetadataRoute } from "next";
import { readServerEnv } from "@/lib/config/server-env";
import { absoluteUrl } from "@/lib/seo/urls";

export default function robots(): MetadataRoute.Robots {
  if (!readServerEnv().allowIndexing) {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: { userAgent: "*", allow: "/", disallow: ["/api/", "/search/", "/status/"] },
    sitemap: absoluteUrl("/sitemap.xml"),
  };
}
