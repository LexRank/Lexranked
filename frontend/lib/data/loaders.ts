import "server-only";

import type { LawFirmSummary, LawyerSummary, RankingSummary } from "@/types/api";
import { getLawFirms, getLawyers, getRankings, type ListQuery } from "@/lib/wordpress/api";
import { WordPressApiError } from "@/lib/wordpress/client";

/**
 * Page-level data helpers. They turn API failures into an explicit
 * "unavailable" result so listing pages degrade gracefully (and are served
 * noindex) instead of crashing when WordPress is unreachable. 404s are
 * handled by the typed getters (null).
 */

export type Loaded<T> = { ok: true; data: T } | { ok: false; error: string };

export async function load<T>(run: () => Promise<T>): Promise<Loaded<T>> {
  try {
    return { ok: true, data: await run() };
  } catch (error) {
    const code = error instanceof WordPressApiError ? error.code : "unexpected_error";
    // "not_configured" is expected in builds without WordPress (e.g. CI); don't log it.
    if (process.env.NODE_ENV !== "test" && code !== "not_configured") {
      // Structured, secret-free log line for the hosting platform.
      console.error(JSON.stringify({ level: "error", source: "lexranked-api", code }));
    }
    return { ok: false, error: code };
  }
}

/** Page through a list endpoint (bounded, deterministic order). */
async function collect<T>(fetchPage: (page: number) => Promise<{ data: T[]; totalPages: number | null }>, maxPages = 20): Promise<T[]> {
  const items: T[] = [];
  for (let page = 1; page <= maxPages; page++) {
    const { data, totalPages } = await fetchPage(page);
    items.push(...data);
    if (!totalPages || page >= totalPages) break;
  }
  return items;
}

export function allLawyers(query: ListQuery = {}): Promise<LawyerSummary[]> {
  return collect((page) => getLawyers({ ...query, page, per_page: 100 }));
}

export function allLawFirms(query: ListQuery = {}): Promise<LawFirmSummary[]> {
  return collect((page) => getLawFirms({ ...query, page, per_page: 100 }));
}

export async function allRankings(): Promise<RankingSummary[]> {
  return collect((page) => getRankings({ per_page: 100, page }));
}
