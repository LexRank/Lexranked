import "server-only";

/**
 * Server-only environment configuration.
 *
 * The `server-only` import makes the build fail if this module is ever
 * imported from a Client Component, so credentials can never be bundled
 * into browser JavaScript.
 */

export interface ServerEnv {
  wordpressApiUrl: string | null;
  wordpressUsername: string | null;
  /** Application password; never log or serialise this value. */
  wordpressAppPassword: string | null;
  allowIndexing: boolean;
}

type EnvSource = Record<string, string | undefined>;

function nonEmpty(value: string | undefined): string | null {
  if (value === undefined) return null;
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

/** Accepts only absolute http(s) URLs; returns them without trailing slash. */
export function parseApiUrl(value: string | undefined): string | null {
  const raw = nonEmpty(value);
  if (raw === null) return null;
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new Error("WORDPRESS_API_URL must be an absolute URL.");
  }
  if (url.protocol !== "https:" && url.protocol !== "http:") {
    throw new Error("WORDPRESS_API_URL must use http or https.");
  }
  return url.toString().replace(/\/+$/, "");
}

/**
 * Search-engine indexing is opt-in: it requires ALLOW_INDEXING=true and is
 * always disabled on Vercel preview/development deployments so preview URLs
 * never compete with production in search results.
 */
export function isIndexingAllowed(env: EnvSource): boolean {
  if (env.VERCEL_ENV === "preview" || env.VERCEL_ENV === "development") {
    return false;
  }
  return env.ALLOW_INDEXING === "true";
}

export function readServerEnv(env: EnvSource = process.env): ServerEnv {
  return {
    wordpressApiUrl: parseApiUrl(env.WORDPRESS_API_URL),
    wordpressUsername: nonEmpty(env.WORDPRESS_USERNAME),
    wordpressAppPassword: nonEmpty(env.WORDPRESS_APP_PASSWORD),
    allowIndexing: isIndexingAllowed(env),
  };
}
