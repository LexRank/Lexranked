import "server-only";

import type {
  ArticleDetail,
  ArticleSummary,
  AttributeDto,
  CityDto,
  DataQualityModelDto,
  EntityDto,
  EntityType,
  LawFirmDetail,
  LawFirmSummary,
  LawyerDetail,
  LawyerSummary,
  PlacementDto,
  PracticeAreaDto,
  RankingDetail,
  RankingSummary,
  ScoreVersionsDto,
  SourceDto,
  StateDto,
  StatusDto,
} from "@/types/api";
import { apiRequest, WordPressApiError, type ApiResponse, type RequestOptions } from "./client";

/**
 * Typed LexRanked API functions. Pages import from here, never from client.ts.
 */

export interface ListQuery {
  page?: number;
  per_page?: number;
  orderby?: string;
  order?: "asc" | "desc";
  state?: string;
  city?: string;
  practice_area?: string;
}

type Opts = Pick<RequestOptions, "revalidate">;

/** Returns null for 404 so pages can call notFound(). */
async function getOrNull<T>(path: string, opts?: Opts): Promise<T | null> {
  try {
    return (await apiRequest<T>(path, { ...opts, tags: ["lexranked", path] })).data;
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 404) return null;
    throw error;
  }
}

export const getStatus = (opts?: Opts) => apiRequest<StatusDto>("status", { revalidate: 0, ...opts });

export const getLawyers = (query: ListQuery = {}, opts?: Opts): Promise<ApiResponse<LawyerSummary[]>> =>
  apiRequest<LawyerSummary[]>("lawyers", { query: { ...query }, ...opts });

export const getLawyer = (slug: string, opts?: Opts) => getOrNull<LawyerDetail>(`lawyers/${encodeURIComponent(slug)}`, opts);

export const getLawFirms = (query: ListQuery = {}, opts?: Opts): Promise<ApiResponse<LawFirmSummary[]>> =>
  apiRequest<LawFirmSummary[]>("law-firms", { query: { ...query }, ...opts });

export const getLawFirm = (slug: string, opts?: Opts) => getOrNull<LawFirmDetail>(`law-firms/${encodeURIComponent(slug)}`, opts);

export interface RankingQuery {
  location?: string;
  practice_area?: string;
  indexable?: boolean;
  page?: number;
  per_page?: number;
}

export const getRankings = (query: RankingQuery = {}, opts?: Opts) =>
  apiRequest<RankingSummary[]>("rankings", { query: { ...query }, ...opts });

export const getRanking = (slug: string, opts?: Opts) => getOrNull<RankingDetail>(`rankings/${encodeURIComponent(slug)}`, opts);

export const getStates = (opts?: Opts) => apiRequest<StateDto[]>("states", opts);

export const getCities = (state?: string, opts?: Opts) => apiRequest<CityDto[]>("cities", { query: { state }, ...opts });

export const getPracticeAreas = (opts?: Opts) => apiRequest<PracticeAreaDto[]>("practice-areas", opts);

export const getSources = (query: { entity_id?: number; per_page?: number } = {}, opts?: Opts) =>
  apiRequest<SourceDto[]>("sources", { query: { ...query }, ...opts });

export interface SearchResult {
  type: "lawyer" | "law_firm";
  id: number;
  slug: string;
  name: string;
  path: string;
  location: import("@/types/api").LocationDto | null;
}

export const searchEntities = (q: string, opts?: Opts) =>
  apiRequest<SearchResult[]>("search", { query: { q, per_page: 20 }, revalidate: 60, ...opts });

export const getScoreVersions = (opts?: Opts) => apiRequest<ScoreVersionsDto>("score-versions", { revalidate: 3600, ...opts });

export const getArticles = (query: { page?: number; per_page?: number; category?: string } = {}, opts?: Opts) =>
  apiRequest<ArticleSummary[]>("articles", { query: { ...query }, ...opts });

export const getArticle = (slug: string, opts?: Opts) => getOrNull<ArticleDetail>(`articles/${encodeURIComponent(slug)}`, opts);

export type PlacementQuery =
  | { product: "sponsored"; ranking: number }
  | { product: "featured"; location: string }
  | { product: "featured"; practice_area: string };

/**
 * Labelled featured / sponsored placements for one page. They are optional
 * extras: a failure returns [] so the organic page always renders.
 */
export async function getPlacements(query: PlacementQuery, opts?: Opts): Promise<PlacementDto[]> {
  try {
    return (await apiRequest<PlacementDto[]>("placements", { query: { ...query }, ...opts })).data;
  } catch (error) {
    console.error(JSON.stringify({ level: "warn", source: "lexranked-api", message: "placements unavailable", error: error instanceof Error ? error.message : "unknown" }));
    return [];
  }
}

export interface ClaimSubmission {
  entityType: "lawyer" | "law_firm";
  entityId: number;
  name: string;
  email: string;
  phone: string;
  role: "self" | "firm_representative";
  barState: string;
  barNumber: string;
  message: string;
  consent: true;
}

/** Submit a profile claim (server action only; the browser never talks to WordPress). */
export const submitClaim = (body: ClaimSubmission) =>
  apiRequest<{ status: string }>("claims", { method: "POST", body, timeoutMs: 15000 });

/** Confirm a claimant's email with the token from the link. */
export const confirmClaim = (token: string) =>
  apiRequest<{ status: string }>("claims/confirm", { method: "POST", body: { token }, timeoutMs: 15000 });

/** Current entity for a current or former slug (renames and merges); null when unknown. */
export async function resolveEntity(type: EntityType, slug: string, opts?: Opts): Promise<EntityDto | null> {
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug)) return null;
  try {
    return (await apiRequest<EntityDto>("entities/resolve", { query: { type, slug }, ...opts })).data;
  } catch (error) {
    if (error instanceof WordPressApiError && (error.status === 404 || error.status === 400)) return null;
    throw error;
  }
}

/** The data dictionary: facts and derived metrics an entity can have. */
export const getAttributes = (opts?: Opts) =>
  apiRequest<{ attributes: AttributeDto[] }>("attributes", { revalidate: 3600, ...opts }).then((r) => r.data.attributes);

/** The published Data Quality model (methodology page). */
export const getDataQualityModel = (opts?: Opts) =>
  apiRequest<DataQualityModelDto>("data-quality", { revalidate: 3600, ...opts }).then((r) => r.data);
