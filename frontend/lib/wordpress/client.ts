import "server-only";

import { readServerEnv, type ServerEnv } from "@/lib/config/server-env";
import type { ApiErrorBody } from "@/types/api";

/**
 * Low-level client for the LexRanked REST API (server-side only).
 *
 * - Timeouts, bounded retries with exponential backoff (network errors,
 *   429 and 5xx only — never other 4xx).
 * - Optional Basic auth with a WordPress Application Password for the
 *   dedicated `lexranked_api` user, which exempts the frontend from the
 *   public rate limit. Only public (`context=view`) data is ever requested,
 *   so responses are safe to cache.
 */

export const API_NAMESPACE = "lexranked/v1";

export class WordPressApiError extends Error {
  constructor(
    message: string,
    readonly status: number | null,
    readonly code: string,
  ) {
    super(message);
    this.name = "WordPressApiError";
  }
}

export class WordPressNotConfiguredError extends WordPressApiError {
  constructor() {
    super("WORDPRESS_API_URL is not configured.", null, "not_configured");
    this.name = "WordPressNotConfiguredError";
  }
}

export type QueryValue = string | number | boolean | undefined | null;

export interface RequestOptions {
  query?: Record<string, QueryValue>;
  /** Seconds to cache (Next data cache). Default 300. `0` disables caching. */
  revalidate?: number;
  tags?: string[];
  timeoutMs?: number;
  retries?: number;
}

export interface ApiResponse<T> {
  data: T;
  total: number | null;
  totalPages: number | null;
}

export interface ClientDeps {
  fetch: typeof fetch;
  env: ServerEnv;
  sleep: (ms: number) => Promise<void>;
}

const DEFAULT_REVALIDATE = 300;
const DEFAULT_TIMEOUT_MS = 8000;
const DEFAULT_RETRIES = 2;
const BASE_BACKOFF_MS = 250;

export function buildUrl(apiUrl: string, path: string, query: Record<string, QueryValue> = {}): string {
  const url = new URL(`${apiUrl.replace(/\/+$/, "")}/${API_NAMESPACE}/${path.replace(/^\/+/, "")}`);
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === "") continue;
    url.searchParams.set(key, String(value));
  }
  return url.toString();
}

export function authHeader(env: ServerEnv): string | null {
  if (!env.wordpressUsername || !env.wordpressAppPassword) return null;
  const token = Buffer.from(`${env.wordpressUsername}:${env.wordpressAppPassword}`).toString("base64");
  return `Basic ${token}`;
}

export function isRetryable(status: number | null): boolean {
  return status === null || status === 429 || status >= 500;
}

export function backoffMs(attempt: number): number {
  return BASE_BACKOFF_MS * 2 ** attempt;
}

function defaultDeps(): ClientDeps {
  return {
    fetch: globalThis.fetch.bind(globalThis),
    env: readServerEnv(),
    sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  };
}

function parseIntHeader(value: string | null): number | null {
  if (value === null) return null;
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : null;
}

export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
  deps: ClientDeps = defaultDeps(),
): Promise<ApiResponse<T>> {
  const { env } = deps;
  if (!env.wordpressApiUrl) throw new WordPressNotConfiguredError();

  const url = buildUrl(env.wordpressApiUrl, path, options.query);
  const headers: Record<string, string> = { Accept: "application/json" };
  const auth = authHeader(env);
  if (auth) headers.Authorization = auth;

  const revalidate = options.revalidate ?? DEFAULT_REVALIDATE;
  const cacheInit: RequestInit =
    revalidate === 0
      ? { cache: "no-store" }
      : { next: { revalidate, tags: options.tags ?? ["lexranked"] } };

  const retries = options.retries ?? DEFAULT_RETRIES;
  let lastError: WordPressApiError | null = null;

  for (let attempt = 0; attempt <= retries; attempt++) {
    if (attempt > 0) await deps.sleep(backoffMs(attempt - 1));

    let response: Response;
    try {
      response = await deps.fetch(url, {
        ...cacheInit,
        headers,
        signal: AbortSignal.timeout(options.timeoutMs ?? DEFAULT_TIMEOUT_MS),
      });
    } catch (error) {
      const reason = error instanceof Error && error.name === "TimeoutError" ? "timeout" : "network_error";
      lastError = new WordPressApiError(`Request to ${path} failed (${reason}).`, null, reason);
      continue;
    }

    if (response.ok) {
      const data = (await response.json()) as T;
      return {
        data,
        total: parseIntHeader(response.headers.get("X-WP-Total")),
        totalPages: parseIntHeader(response.headers.get("X-WP-TotalPages")),
      };
    }

    let body: Partial<ApiErrorBody> = {};
    try {
      body = (await response.json()) as ApiErrorBody;
    } catch {
      // Non-JSON error page (proxy, PHP fatal); keep status only.
    }
    lastError = new WordPressApiError(
      body.message ?? `Request to ${path} failed with HTTP ${response.status}.`,
      response.status,
      body.code ?? `http_${response.status}`,
    );
    if (!isRetryable(response.status)) break;
  }

  throw lastError ?? new WordPressApiError(`Request to ${path} failed.`, null, "unknown");
}
