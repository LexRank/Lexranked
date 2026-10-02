import type { ComparableType } from "@/types/api";

/**
 * Comparison URLs (Etap E): /compare?lawyer=12&lawyer=34 or /compare?firm=5&firm=9.
 * Entity IDs are the stable registry IDs, so links survive renames. Compare
 * pages are noindex: they are built on request for any pair, and indexing every
 * combination would be thin programmatic SEO.
 */

export const COMPARE_MIN = 2;
export const COMPARE_MAX = 4;

const PARAM: Record<ComparableType, string> = { lawyer: "lawyer", law_firm: "firm" };

export type CompareQuery =
  | { ok: true; type: ComparableType; ids: number[] }
  | { ok: false; reason: "empty" | "mixed" | "invalid" | "count" };

type SearchParams = Record<string, string | string[] | undefined>;

const list = (v: string | string[] | undefined): string[] => (v === undefined ? [] : Array.isArray(v) ? v : [v]);

export function parseCompareQuery(params: SearchParams): CompareQuery {
  const lawyers = list(params.lawyer);
  const firms = list(params.firm);
  if (lawyers.length === 0 && firms.length === 0) return { ok: false, reason: "empty" };
  if (lawyers.length > 0 && firms.length > 0) return { ok: false, reason: "mixed" };
  const raw = lawyers.length > 0 ? lawyers : firms;
  if (!raw.every((v) => /^[1-9][0-9]{0,9}$/.test(v))) return { ok: false, reason: "invalid" };
  const ids = [...new Set(raw.map(Number))];
  if (ids.length < COMPARE_MIN || ids.length > COMPARE_MAX) return { ok: false, reason: "count" };
  return { ok: true, type: lawyers.length > 0 ? "lawyer" : "law_firm", ids };
}

/** Link to a comparison; null when there are not enough distinct IDs. */
export function compareHref(type: ComparableType, ids: Array<number | null | undefined>): string | null {
  const unique = [...new Set(ids.filter((id): id is number => typeof id === "number" && id > 0))].slice(0, COMPARE_MAX);
  if (unique.length < COMPARE_MIN) return null;
  return `/compare/?${unique.map((id) => `${PARAM[type]}=${id}`).join("&")}`;
}
