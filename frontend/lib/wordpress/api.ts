import "server-only";

import type {
  ArticleDetail,
  ArticleSummary,
  CityDto,
  LawFirmDetail,
  LawFirmSummary,
  LawyerDetail,
  LawyerSummary,
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
