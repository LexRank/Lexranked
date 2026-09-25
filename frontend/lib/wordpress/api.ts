import "server-only";

import type {
  CityDto,
  LawFirmDetail,
  LawFirmSummary,
  LawyerDetail,
  LawyerSummary,
  PracticeAreaDto,
  RankingDetail,
  RankingSummary,
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

export const getRankings = (query: { location?: string; practice_area?: string; indexable?: boolean; per_page?: number } = {}, opts?: Opts) =>
  apiRequest<RankingSummary[]>("rankings", { query: { ...query }, ...opts });

export const getRanking = (slug: string, opts?: Opts) => getOrNull<RankingDetail>(`rankings/${encodeURIComponent(slug)}`, opts);

export const getStates = (opts?: Opts) => apiRequest<StateDto[]>("states", opts);

export const getCities = (state?: string, opts?: Opts) => apiRequest<CityDto[]>("cities", { query: { state }, ...opts });

export const getPracticeAreas = (opts?: Opts) => apiRequest<PracticeAreaDto[]>("practice-areas", opts);

export const getSources = (query: { entity_id?: number; per_page?: number } = {}, opts?: Opts) =>
  apiRequest<SourceDto[]>("sources", { query: { ...query }, ...opts });
