import { cache } from "react";
import { getCities } from "@/lib/wordpress/api";

/**
 * Whether a city hub page exists (eligibility, Etap G). Profiles link to
 * their city only when it does: a city below the threshold returns 404, and
 * a link to it would be a broken internal link.
 */
export const cityPageExists = cache(async (citySlug: string | null | undefined): Promise<boolean> => {
  if (!citySlug) return false;
  try {
    const city = (await getCities()).data.find((c) => c.slug === citySlug);
    return city?.eligibility?.exists === true;
  } catch {
    return false;
  }
});
