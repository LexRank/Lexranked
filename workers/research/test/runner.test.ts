import { describe, expect, it } from 'vitest';
import { ApiError, LeaseLostError, ResearchApi } from '../src/api.js';
import type { WorkerConfig } from '../src/config.js';
import { loadConfig, ConfigError } from '../src/config.js';
import { SafeFetcher } from '../src/fetcher.js';
import { JsonLogger, redact } from '../src/logger.js';
import { rowClaims, rowVerifications } from '../src/pipeline/discovery.js';
import { runOnce, SimulatedCrash } from '../src/runner.js';
import { FakeWordPress } from './fakeWordPress.js';

const BASE = 'http://wp.test/wp-json/lexranked/v1';

function setup(overrides: Partial<WorkerConfig> = {}) {
  const wp = new FakeWordPress();
  const config: WorkerConfig = {
    apiUrl: BASE,
    user: 'worker',
    appPassword: 'abcd efgh ijkl',
    workerId: 'test-worker',
    jobTypes: ['candidate_discovery', 'source_refresh'],
    dataDir: 'fixtures/datasets',
    userAgent: 'LexRankedBot/0.5',
    pollSeconds: 1,
    heartbeatSeconds: 3600,
    fetchTimeoutMs: 1000,
    fetchMaxBytes: 100000,
    perHostIntervalMs: 0,
    allowPrivateNetwork: false,
    batchSize: 2,
    crashAfterRows: null,
    ai: null,
    ...overrides,
  };
  const lines: string[] = [];
  const api = new ResearchApi({ baseUrl: BASE, user: config.user, appPassword: config.appPassword, userAgent: config.userAgent, fetchImpl: wp.fetch, sleep: async () => {} });
  // No network in unit tests: every website fetch is refused.
  const fetcher = new SafeFetcher({ userAgent: config.userAgent, timeoutMs: 1000, maxBytes: 1000, perHostIntervalMs: 0, allowPrivateNetwork: false, resolve: async () => ['10.0.0.1'] });
  const logger = new JsonLogger((l) => lines.push(l));
  const deps = { api, config, fetcher, logger, shutdown: new AbortController().signal };
  return { wp, deps, lines };
}

describe('ResearchApi', () => {
  it('retries transient outages, then succeeds', async () => {
    const { wp, deps } = setup();
    wp.failNext = 2;
    await expect(deps.api.claim('w', ['candidate_discovery'])).resolves.toEqual({ job: null });
    expect(wp.requests).toHaveLength(3);
  });

  it('does not retry client errors and surfaces lease loss', async () => {
    const { wp, deps } = setup();
    const job = wp.addJob('candidate_discovery', {});
    await expect(deps.api.heartbeat({ ...job, title: '', scope: { locations: [], practiceAreas: [] }, token: 'bogus' }, {})).rejects.toBeInstanceOf(LeaseLostError);
    await expect(deps.api.request('GET', '/nope')).rejects.toBeInstanceOf(ApiError);
    expect(wp.requests).toHaveLength(2);
  });
});

describe('runOnce — candidate discovery', () => {
  it('processes the dataset, checkpoints each batch and completes', async () => {
    const { wp, deps } = setup();
    const job = wp.addJob('candidate_discovery', { dataset: 'fictional-demo', fetch_websites: false });
    expect(await runOnce(deps)).toBe('completed');
    expect(job.status).toBe('completed');
    expect(job.cursor).toBe('row:6');
    expect(job.processedCount).toBe(6);
    expect(job.stats).toMatchObject({ candidates_created: 4, candidates_needs_review: 1, rows_invalid: 1 });
    expect(wp.requests.filter((r) => r.endsWith('/heartbeat'))).toHaveLength(3);
    expect(wp.verifications.size).toBe(4); // license + bar_status for the two active bar-sourced lawyers.
    // Resolution identifiers travel with candidates (the CMS weighs them; a bar number is strong).
    const jordan = wp.candidateItems.find((c) => String(c.name).startsWith('Jordan'));
    expect(jordan?.identifiers).toEqual({ phone: '+1 305 555 0101', bar_state: 'FL', bar_number: '1001' });
    expect(await runOnce(deps)).toBe('idle');
  });

  it('resumes after a crash from the last checkpoint without duplicating anything', async () => {
    const crashing = setup({ crashAfterRows: 4 });
    const { wp } = crashing;
    const job = wp.addJob('candidate_discovery', { dataset: 'fictional-demo', fetch_websites: false });
    const crash = (): never => {
      throw new SimulatedCrash('simulated');
    };
    await expect(runOnce({ ...crashing.deps, crash })).rejects.toBeInstanceOf(SimulatedCrash);
    expect(job.status).toBe('running');
    expect(job.cursor).toBe('row:4');
    const claimsBefore = wp.claims.size;

    // Lease expires; a new worker process (same fake WordPress) picks it up.
    wp.expireLease(job);
    const fresh = setup();
    const api = new ResearchApi({ baseUrl: BASE, user: 'worker', appPassword: 'x', userAgent: 'LexRankedBot/0.5', fetchImpl: wp.fetch, sleep: async () => {} });
    expect(await runOnce({ ...fresh.deps, api })).toBe('completed');
    expect(job.retryCount).toBe(1);
    expect(job.processedCount).toBe(6);
    expect(wp.logs.some((l) => l.message.startsWith('Resuming at row 5'))).toBe(true);

    // Replaying everything from the start is idempotent too.
    const replay = new FakeWordPress();
    replay.candidates = wp.candidates;
    replay.claims = wp.claims;
    replay.sources = wp.sources;
    replay.verifications = wp.verifications;
    const again = replay.addJob('candidate_discovery', { dataset: 'fictional-demo', fetch_websites: false });
    const replayApi = new ResearchApi({ baseUrl: BASE, user: 'worker', appPassword: 'x', userAgent: 'x', fetchImpl: replay.fetch, sleep: async () => {} });
    const size = wp.claims.size;
    expect(await runOnce({ ...fresh.deps, api: replayApi })).toBe('completed');
    expect(replay.claims.size).toBe(size);
    expect(again.stats).toMatchObject({ claims_duplicate: size });
    expect(claimsBefore).toBeLessThan(size);
  });

  it('fails permanently on a bad dataset and retryably on outages', async () => {
    const { wp, deps } = setup();
    const bad = wp.addJob('candidate_discovery', { dataset: '../../etc/passwd' });
    expect(await runOnce(deps)).toBe('failed');
    expect(bad).toMatchObject({ status: 'failed', retryable: false });

    const flaky = setup();
    const job = flaky.wp.addJob('candidate_discovery', { dataset: 'fictional-demo', fetch_websites: false });
    let calls = 0;
    const original = flaky.wp.fetch;
    flaky.wp.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      calls++;
      if (calls === 3) return new Response('{"code":"x"}', { status: 503 });
      return original(input, init);
    }) as typeof fetch;
    const api = new ResearchApi({ baseUrl: BASE, user: 'w', appPassword: 'x', userAgent: 'x', fetchImpl: flaky.wp.fetch, sleep: async () => {}, retries: 0 });
    expect(await runOnce({ ...flaky.deps, api })).toBe('failed');
    expect(job).toMatchObject({ status: 'failed', retryable: true });
  });

  it('stops without writing when the job is cancelled', async () => {
    const { wp, deps } = setup();
    const job = wp.addJob('candidate_discovery', { dataset: 'fictional-demo', fetch_websites: false });
    const original = wp.fetch;
    wp.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      if (String(input).endsWith('/heartbeat')) job.status = 'cancelled';
      return original(input, init);
    }) as typeof fetch;
    const api = new ResearchApi({ baseUrl: BASE, user: 'w', appPassword: 'x', userAgent: 'x', fetchImpl: wp.fetch, sleep: async () => {} });
    expect(await runOnce({ ...deps, api })).toBe('lease_lost');
    expect(wp.requests.some((r) => r.endsWith('/fail') || r.endsWith('/complete'))).toBe(false);
  });
});

describe('row mapping', () => {
  const row = {
    line: 1,
    entity_type: 'lawyer' as const,
    name: 'Jordan Sample',
    source_url: 'https://bar.fixture.test/1',
    source_type: 'bar_association',
    retrieved_at: '2026-09-01T00:00:00.000Z',
    city: 'Miami',
    bar_status: 'suspended',
    confidence: 0.9,
  };

  it('only restates fields present in the source row', () => {
    expect(rowClaims(row, 7, 3).map((c) => c.field_name)).toEqual(['name', 'city', 'bar_status']);
    expect(rowClaims(row, 7, 3)[0]).toMatchObject({ entity_id: 7, source_id: 3, confidence: 0.9 });
  });

  it('requests verification only from authoritative sources', () => {
    expect(rowVerifications(row, 7, undefined).map((v) => [v.verification_type, v.status])).toEqual([
      ['license', 'failed'],
      ['bar_status', 'verified'],
    ]);
    expect(rowVerifications({ ...row, source_type: 'review_platform' }, 7, undefined)).toEqual([]);
  });
});

describe('config and logging', () => {
  it('requires credentials and https for remote APIs', () => {
    expect(() => loadConfig({})).toThrow(ConfigError);
    expect(() => loadConfig({ LEXRANKED_API_URL: 'http://cms.example.com/wp-json/lexranked/v1', LEXRANKED_WORKER_USER: 'w', LEXRANKED_WORKER_APP_PASSWORD: 'p' })).toThrow(/https/);
    const cfg = loadConfig({ LEXRANKED_API_URL: 'http://localhost:8080/wp-json/lexranked/v1/', LEXRANKED_WORKER_USER: 'w', LEXRANKED_WORKER_APP_PASSWORD: 'p' });
    expect(cfg.apiUrl).toBe('http://localhost:8080/wp-json/lexranked/v1');
    expect(() => loadConfig({ LEXRANKED_API_URL: 'https://x.test', LEXRANKED_WORKER_USER: 'w', LEXRANKED_WORKER_APP_PASSWORD: 'p', LEXRANKED_WORKER_JOB_TYPES: 'ranking_recalculation' })).toThrow(/Unsupported/);
  });

  it('enables AI only with both key and model, and gates AI job types on it', () => {
    const base = { LEXRANKED_API_URL: 'https://x.test/wp-json/lexranked/v1', LEXRANKED_WORKER_USER: 'w', LEXRANKED_WORKER_APP_PASSWORD: 'p' };
    expect(loadConfig(base).ai).toBeNull();
    expect(loadConfig(base).jobTypes).toEqual(['candidate_discovery', 'source_refresh']);
    expect(() => loadConfig({ ...base, OPENAI_API_KEY: 'sk-x' })).toThrow(/both/);
    expect(() => loadConfig({ ...base, LEXRANKED_WORKER_JOB_TYPES: 'content_generation' })).toThrow(/OPENAI/);
    expect(() => loadConfig({ ...base, OPENAI_API_KEY: 'sk-x', OPENAI_MODEL: 'm', OPENAI_BASE_URL: 'http://evil.example/v1' })).toThrow(/https/);
    const cfg = loadConfig({ ...base, OPENAI_API_KEY: 'sk-x', OPENAI_MODEL: 'm' });
    expect(cfg.ai).toMatchObject({ model: 'm', baseUrl: 'https://api.openai.com/v1', maxCallsPerJob: 200 });
    expect(cfg.jobTypes).toContain('content_generation');
  });

  it('never logs secrets', () => {
    expect(redact({ appPassword: 'x', nested: { Authorization: 'Basic y', url: 'u' } })).toEqual({ appPassword: '[redacted]', nested: { Authorization: '[redacted]', url: 'u' } });
    const lines: string[] = [];
    new JsonLogger((l) => lines.push(l)).log('info', 'hi', { token: 'secret' });
    expect(lines[0]).not.toContain('secret');
  });
});
