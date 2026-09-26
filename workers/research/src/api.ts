/**
 * Client for the private LexRanked research API (WordPress, lexranked/v1).
 *
 * Transient failures (network, 429, 502–504) are retried with exponential
 * backoff and jitter; losing the job lease is surfaced as LeaseLostError so
 * the worker stops touching the job immediately.
 */

export interface JobScopeTerm {
  id: number;
  slug: string;
  name: string;
  state_code?: string | null;
}

export interface Job {
  id: number;
  title: string;
  jobType: string;
  status: string;
  params: Record<string, unknown>;
  scope: { locations: JobScopeTerm[]; practiceAreas: JobScopeTerm[] };
  cursor: string | null;
  processedCount: number;
  retryCount: number;
  stats: Record<string, number> | null;
}

export interface ClaimedJob extends Job {
  token: string;
}

export interface LogEntry {
  level: 'debug' | 'info' | 'warning' | 'error';
  stage: string;
  message: string;
  context?: Record<string, unknown>;
}

export interface Progress {
  cursor?: string | null;
  processed_count?: number;
  stats?: Record<string, number>;
  logs?: LogEntry[];
}

export interface ItemError {
  index: number;
  error: { field: string; message: string };
}

export type CandidateResult =
  | ItemError
  | { index: number; candidateId: number; created: boolean; status: string; entityId: number | null; entityType: string; reason: string | null };

export type ClaimResult = ItemError | { index: number; claimId: number; duplicate: boolean };
export type SourceResult = ItemError | { index: number; sourceId: number; created: boolean; tier: number };
export type VerificationResult =
  | ItemError
  | { index: number; verificationId: number; status: string; downgraded: boolean; duplicate: boolean };

export interface Target {
  id: number;
  entityType: 'lawyer' | 'law_firm';
  status: string;
  name: string;
  website: string | null;
}

export interface CandidateInput {
  entity_type: 'lawyer' | 'law_firm';
  name: string;
  city?: string;
  state?: string;
  practice_area?: string;
  website?: string;
  source_url: string;
  source_type: string;
  payload?: Record<string, unknown>;
}

export interface ClaimInput {
  entity_id: number;
  field_name: string;
  value: unknown;
  source_url: string;
  source_type: string;
  source_id?: number;
  retrieved_at: string;
  confidence: number;
}

export interface VerificationInput {
  entity_id: number;
  verification_type: string;
  status: 'verified' | 'failed' | 'pending';
  source_url: string;
  source_type: string;
  source_id?: number;
  notes?: string;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string,
  ) {
    super(message);
  }
}

/** The job was cancelled or another worker took it over: stop immediately. */
export class LeaseLostError extends ApiError {}

export const MAX_BATCH = 100;

export interface ApiOptions {
  baseUrl: string;
  user: string;
  appPassword: string;
  userAgent: string;
  fetchImpl?: typeof fetch;
  retries?: number;
  retryBaseMs?: number;
  timeoutMs?: number;
  sleep?: (ms: number) => Promise<void>;
}

const RETRYABLE_STATUS = new Set([429, 502, 503, 504]);

export class ResearchApi {
  private readonly auth: string;
  private readonly fetchImpl: typeof fetch;
  private readonly sleep: (ms: number) => Promise<void>;

  constructor(private readonly opts: ApiOptions) {
    this.auth = 'Basic ' + Buffer.from(`${opts.user}:${opts.appPassword}`).toString('base64');
    this.fetchImpl = opts.fetchImpl ?? fetch;
    this.sleep = opts.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
  }

  claim(worker: string, types: string[]): Promise<{ job: ClaimedJob | null }> {
    return this.request('POST', '/research/jobs/claim', { body: { worker, types } });
  }

  heartbeat(job: ClaimedJob, progress: Progress): Promise<Job> {
    return this.request('POST', `/research/jobs/${job.id}/heartbeat`, { body: progress, lease: job.token });
  }

  complete(job: ClaimedJob, progress: Progress): Promise<Job> {
    return this.request('POST', `/research/jobs/${job.id}/complete`, { body: progress, lease: job.token });
  }

  fail(job: ClaimedJob, error: string, retryable: boolean, progress: Progress = {}): Promise<Job> {
    return this.request('POST', `/research/jobs/${job.id}/fail`, {
      body: { ...progress, error: error.slice(0, 2000), retryable },
      lease: job.token,
    });
  }

  targets(job: ClaimedJob, after: number, limit: number): Promise<Target[]> {
    return this.request('GET', `/research/jobs/${job.id}/targets?after=${after}&limit=${limit}`, { lease: job.token });
  }

  async candidates(job: ClaimedJob, items: CandidateInput[]): Promise<CandidateResult[]> {
    return this.batched(job, 'candidates', items);
  }

  async sources(job: ClaimedJob, items: { url: string; source_type: string; title?: string }[]): Promise<SourceResult[]> {
    return this.batched(job, 'sources', items);
  }

  async claims(job: ClaimedJob, items: ClaimInput[]): Promise<ClaimResult[]> {
    const out: ClaimResult[] = [];
    for (let i = 0; i < items.length; i += MAX_BATCH) {
      const chunk = items.slice(i, i + MAX_BATCH);
      const res = await this.request<{ results: ClaimResult[] }>('POST', `/research/jobs/${job.id}/claims`, { body: { items: chunk }, lease: job.token });
      out.push(...res.results.map((r) => ({ ...r, index: r.index + i })));
    }
    return out;
  }

  async verifications(job: ClaimedJob, items: VerificationInput[]): Promise<VerificationResult[]> {
    return this.batched(job, 'verifications', items);
  }

  private async batched<T extends { index: number }>(job: ClaimedJob, path: string, items: unknown[]): Promise<T[]> {
    const out: T[] = [];
    for (let i = 0; i < items.length; i += MAX_BATCH) {
      const chunk = items.slice(i, i + MAX_BATCH);
      if (chunk.length === 0) continue;
      const res = await this.request<{ results: T[] }>('POST', `/research/jobs/${job.id}/${path}`, { body: { items: chunk }, lease: job.token });
      out.push(...res.results.map((r) => ({ ...r, index: r.index + i })));
    }
    return out;
  }

  async request<T>(method: 'GET' | 'POST', path: string, init: { body?: unknown; lease?: string } = {}): Promise<T> {
    const retries = this.opts.retries ?? 4;
    const base = this.opts.retryBaseMs ?? 500;
    let lastError: unknown;
    for (let attempt = 0; attempt <= retries; attempt++) {
      if (attempt > 0) {
        // Exponential backoff with full jitter.
        await this.sleep(Math.round(Math.random() * base * 2 ** (attempt - 1)) + base);
      }
      let res: Response;
      try {
        const headers: Record<string, string> = {
          Authorization: this.auth,
          Accept: 'application/json',
          'User-Agent': this.opts.userAgent,
        };
        if (init.body !== undefined) headers['Content-Type'] = 'application/json';
        if (init.lease !== undefined) headers['X-LexRanked-Lease'] = init.lease;
        res = await this.fetchImpl(this.opts.baseUrl + path, {
          method,
          headers,
          body: init.body === undefined ? null : JSON.stringify(init.body),
          signal: AbortSignal.timeout(this.opts.timeoutMs ?? 30_000),
        });
      } catch (err) {
        lastError = err; // Network error or timeout: retry.
        continue;
      }
      if (res.ok) {
        return (await res.json()) as T;
      }
      let code = 'http_' + res.status;
      let message = `HTTP ${res.status}`;
      try {
        const body = (await res.json()) as { code?: string; message?: string };
        code = body.code ?? code;
        message = body.message ?? message;
      } catch {
        // Non-JSON error body.
      }
      if (code === 'lexranked_lease_lost' || code === 'lexranked_job_cancelled') {
        throw new LeaseLostError(message, res.status, code);
      }
      lastError = new ApiError(`${method} ${path}: ${message}`, res.status, code);
      if (!RETRYABLE_STATUS.has(res.status)) {
        throw lastError;
      }
    }
    throw lastError instanceof Error ? lastError : new Error(String(lastError));
  }
}

export function isItemError(r: { index: number }): r is ItemError {
  return 'error' in r;
}
