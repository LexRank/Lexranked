/**
 * In-memory stand-in for the WordPress research API with the same
 * idempotency semantics (candidate dedupe keys, claim hashes, source URLs,
 * lease tokens). The PHP side has its own tests; this lets the worker's
 * resume/retry behaviour be tested without Docker.
 */

import { normalizeName } from '../src/normalize.js';

export interface FakeJob {
  id: number;
  jobType: string;
  status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
  params: Record<string, unknown>;
  cursor: string | null;
  processedCount: number;
  retryCount: number;
  stats: Record<string, number> | null;
  token: string | null;
  error: string | null;
  retryable: boolean | null;
}

export class FakeWordPress {
  jobs: FakeJob[] = [];
  candidates = new Map<string, { id: number; entityId: number | null; status: string }>();
  sources = new Map<string, number>();
  claims = new Map<string, number>();
  verifications = new Map<string, number>();
  logs: { jobId: number; level: string; message: string }[] = [];
  requests: string[] = [];
  /** Public data for content generation. */
  rankings: { id: number; title: string }[] = [];
  practiceAreas = [{ id: 1, slug: 'personal-injury', name: 'Personal Injury' }];
  drafts: Record<string, unknown>[] = [];
  reviewQueue: Record<string, unknown>[] = [];
  notes: Record<string, unknown>[] = [];
  /** Respond 503 to the next N requests (transient outage). */
  failNext = 0;
  private seq = 1000;

  addJob(jobType: string, params: Record<string, unknown>): FakeJob {
    const job: FakeJob = { id: this.jobs.length + 1, jobType, status: 'pending', params, cursor: null, processedCount: 0, retryCount: 0, stats: null, token: null, error: null, retryable: null };
    this.jobs.push(job);
    return job;
  }

  /** Simulate lease expiry after a crash. */
  expireLease(job: FakeJob): void {
    job.token = null;
    job.status = 'running';
    (job as FakeJob & { expired?: boolean }).expired = true;
  }

  fetch = (async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    const url = new URL(String(input));
    const path = url.pathname.replace(/^\/wp-json\/lexranked\/v1/, '');
    this.requests.push(`${init?.method ?? 'GET'} ${path}`);
    if (this.failNext > 0) {
      this.failNext--;
      return json({ code: 'unavailable', message: 'down' }, 503);
    }
    const headers = new Headers(init?.headers);
    if (!headers.get('authorization')?.startsWith('Basic ')) return json({ code: 'rest_forbidden' }, 401);
    const body = init?.body ? JSON.parse(String(init.body)) : {};

    if (path === '/research/jobs/claim') {
      const job = this.jobs.find((j) => j.status === 'pending' || (j.status === 'running' && (j as { expired?: boolean }).expired) || (j.status === 'failed' && j.retryable));
      if (!job) return json({ job: null });
      if (job.status !== 'pending') job.retryCount++;
      (job as { expired?: boolean }).expired = false;
      job.status = 'running';
      job.token = 'tok-' + ++this.seq;
      return json({ job: this.view(job, true) });
    }
    if (path === '/rankings') return json(this.rankings.map((r) => ({ id: r.id, title: r.title })));
    const rm = /^\/rankings\/(\d+)$/.exec(path);
    if (rm) {
      const r = this.rankings.find((x) => x.id === Number(rm[1]));
      return r ? json(r) : json({ code: 'lexranked_not_found' }, 404);
    }
    if (path === '/practice-areas') return json(this.practiceAreas);

    const m = /^\/research\/jobs\/(\d+)\/([a-z-]+)/.exec(path);
    const job = m ? this.jobs.find((j) => j.id === Number(m[1])) : undefined;
    if (!m || !job) return json({ code: 'lexranked_not_found' }, 404);
    if (job.status === 'cancelled') return json({ code: 'lexranked_job_cancelled', message: 'cancelled' }, 409);
    if (job.status !== 'running' || headers.get('x-lexranked-lease') !== job.token) {
      return json({ code: 'lexranked_lease_lost', message: 'lease lost' }, 409);
    }
    const action = m[2];
    const items: Record<string, unknown>[] = body.items ?? [];
    for (const l of body.logs ?? []) this.logs.push({ jobId: job.id, level: l.level, message: l.message });

    switch (action) {
      case 'heartbeat':
      case 'complete':
      case 'fail':
        if ('cursor' in body) job.cursor = body.cursor;
        if (typeof body.processed_count === 'number') job.processedCount = Math.max(job.processedCount, body.processed_count);
        if (body.stats) job.stats = body.stats;
        if (action === 'complete') {
          job.status = 'completed';
          job.token = null;
        }
        if (action === 'fail') {
          job.status = 'failed';
          job.error = body.error;
          job.retryable = body.retryable;
          job.token = null;
        }
        return json(this.view(job, false));
      case 'sources':
        return json({
          results: items.map((it, index) => {
            const url = String(it.url);
            if (!this.sources.has(url)) this.sources.set(url, ++this.seq);
            return { index, sourceId: this.sources.get(url), created: true, tier: 1 };
          }),
        });
      case 'candidates':
        return json({
          results: items.map((it, index) => {
            const name = normalizeName(String(it.name), it.entity_type as 'lawyer');
            if (it.entity_type === 'lawyer' && !name.includes(' ')) {
              return { index, error: { field: 'name', message: 'name must contain a first and last name' } };
            }
            const key = [it.entity_type, name, it.city ?? '', it.state ?? ''].join('|');
            const existing = this.candidates.get(key);
            if (existing) return { index, candidateId: existing.id, created: false, status: existing.status, entityId: existing.entityId, entityType: it.entity_type, reason: null };
            const review = String(it.name).includes('Placeholder');
            const created = { id: ++this.seq, entityId: review ? null : ++this.seq, status: review ? 'needs_review' : 'created' };
            this.candidates.set(key, created);
            return { index, candidateId: created.id, created: true, status: created.status, entityId: created.entityId, entityType: it.entity_type, reason: 'fake' };
          }),
        });
      case 'claims':
        return json({
          results: items.map((it, index) => {
            const key = JSON.stringify([it.entity_id, it.field_name, it.value, it.source_url, it.source_type]);
            const duplicate = this.claims.has(key);
            if (!duplicate) this.claims.set(key, ++this.seq);
            return { index, claimId: this.claims.get(key), duplicate };
          }),
          applied: {},
          review: [],
        });
      case 'verifications':
        return json({
          results: items.map((it, index) => {
            const key = JSON.stringify([it.entity_id, it.verification_type, it.status, it.source_url]);
            const duplicate = this.verifications.has(key);
            if (!duplicate) this.verifications.set(key, ++this.seq);
            return { index, verificationId: this.verifications.get(key), status: it.status, downgraded: false, duplicate };
          }),
        });
      case 'targets':
        return json([]);
      case 'review-candidates': {
        const after = Number(url.searchParams.get('after') ?? 0);
        const limit = Number(url.searchParams.get('limit') ?? 25);
        return json(this.reviewQueue.filter((c) => (c.id as number) > after).slice(0, limit));
      }
      case 'candidate-notes':
        this.notes.push(...items);
        return json({ results: items.map((it, index) => ({ index, candidateId: it.candidate_id, stored: true })) });
      case 'content-drafts': {
        const hasError = (body.qa?.issues ?? []).some((i: { severity: string }) => i.severity === 'error');
        const existing = this.drafts.findIndex((d) => d.target_id === body.target_id && d.job === job.id);
        const draft = { ...body, job: job.id, qaStatus: hasError ? 'needs_review' : body.qa.status };
        if (existing >= 0) this.drafts[existing] = draft;
        else this.drafts.push(draft);
        return json({ draftId: 5000 + this.drafts.length, qaStatus: draft.qaStatus, updated: existing >= 0 });
      }
    }
    return json({ code: 'rest_no_route' }, 404);
  }) as typeof fetch;

  private view(job: FakeJob, withToken: boolean) {
    return {
      id: job.id,
      title: `job ${job.id}`,
      jobType: job.jobType,
      status: job.status,
      params: job.params,
      scope: { locations: [], practiceAreas: [] },
      cursor: job.cursor,
      processedCount: job.processedCount,
      retryCount: job.retryCount,
      stats: job.stats,
      ...(withToken ? { token: job.token } : {}),
    };
  }
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}
